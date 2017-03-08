<?php
require_once('autoload.php');
require_once('bitcoin_pizza.inc.php');

use Twitterbot\Lib\Logger;
use Twitterbot\Lib\Config;
use Twitterbot\Lib\Auth;
use Twitterbot\Lib\Format;
use Twitterbot\Lib\Tweet;

(new BitcoinPizza)->run();

class BitcoinPizza
{
    public function __construct()
    {
        $this->sUsername = 'bitcoin_pizza';
        $this->logger = new Logger;
    }

    public function run()
    {
        $this->oConfig = new Config;
        if ($this->oConfig->load($this->sUsername)) {

            if ((new Auth($this->oConfig))->isUserAuthed($this->sUsername)) {

                if ($lBtcPrice = $this->getBitcoinPrice()) {

                    $sChange = $this->getDailyChange($lBtcPrice);

                    $oRecord = (object)[
                        'worth' => number_format($lBtcPrice * 10000),
                        'change' => $sChange,
                        'suffix' => (date('n') == 5 && date('d') == 22) ? $this->oConfig->get('birthday_suffix') : '',
                    ];
                    $sTweet = (new Format($this->oConfig))->format($oRecord);

                    if ($sTweet) {
                        (new Tweet($this->oConfig))->post(trim($sTweet));
                    }
                }
            }
        }

        $this->logger->output('Done!');
    }

    private function getBitcoinPrice() {

        $this->logger->output('Getting BTC price in USD from all exchanges..');

        $aBtcTrackers = $this->oConfig->get('trackers');

        $oContext = stream_context_create(array(
            'http' => array('timeout' => 8)
        ));

        $aBtcPrices = array();
        foreach ($aBtcTrackers as $oTracker) {

            if (!$oTracker->enabled) {
                continue;
            }

            $this->logger->output("- Checking BTC price at %s..", $oTracker->name);
            $oResult = @json_decode(file_get_contents($oTracker->url, false, $oContext));

            $aSteps = explode('.', $oTracker->target);
            foreach ($aSteps as $sStep) {
                if (!empty($oResult->{$sStep})) {
                    $oResult = $oResult->{$sStep};
                } else {
                    $this->logger->write(3, sprintf(' - Skipping exchange %s because json error', $oTracker->name));
                    $oResult = false;
                    break;
                }
            }

            if (!empty($oResult)) {
                if (is_array($oResult)) {
                    $oResult = array_shift($oResult);
                }
                if (is_numeric($oResult)) {
                    $this->logger->output('- price is ' . (float)$oResult);
                    $aBtcPrices[$oTracker->name] = (float)$oResult;
                }
            }
        }

        $this->logger->output('- Average price across all exchanges: $ %f', ($aBtcPrices ? array_sum($aBtcPrices) / count($aBtcPrices) : 0));

        return ($aBtcPrices ? array_sum($aBtcPrices) / count($aBtcPrices) : false);
    }

    private function getDailyChange($lBtcPrice) {

        $this->logger->output('Formatting daily change..');

        //check for valid number
        if (empty($lBtcPrice) || !is_numeric($lBtcPrice)) {
            $this->logger->write(3, sprintf('- argument is invalid (%s)', $lBtcPrice));
            $this->logger->output('- Argument is invalid: %s', $lBtcPrice);
            return false;
        }

        //fetch avg price from last run and calculate percent change
        $oLastRun = $this->oConfig->get('lastrun');
        if (!empty($oLastRun->last_price)) {

            $iChange = 100 * ($lBtcPrice - $oLastRun->last_price) / $oLastRun->last_price;
            if ($iChange < 1 && $iChange > -1 && $iChange != 0) {
                $sChange = number_format($iChange, 2);

                $sChange = ($sChange > 0 ? '+' : '') . $sChange;
            } else {
                $sChange = ($iChange > 0 ? '+' : '') . (int)$iChange;
            }
        } else {
            $iChange = 100;
            $sChange = '100';
        }

        //save current avg price for next run
        $this->oConfig->set('lastrun', 'last_price', $lBtcPrice);
        $this->oConfig->writeConfig();

        return $sChange . '%';
    }
}
