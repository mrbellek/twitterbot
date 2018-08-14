<?php
require_once('twitteroauth.php');
require_once('logger.php');
require_once('bitcoinpizza.inc.php');

$o = new BitcoinPizzaBot(array(
    'sUsername' => 'bitcoin_pizza',
    'sLastRunFile' => 'bitcoinpizza.json',
    'sTweetFormat' => 'The #Bitcoin pizza is worth $:worth today. (:change from yesterday)',
    'sBirthdaySuffix' => ' Today is Bitcoin pizza day!',
));
$o->run();

class BitcoinPizzaBot {

    private $sUsername;
    private $sLogFile;
    private $iLogLevel = 3; //increase for debugging
    private $sSettingsFile;

    private $aBtcTickers = array(
        'bitstamp' => array(
            'url' => 'https://www.bitstamp.net/api/ticker/',
            'target' => 'last',
        ),
        /*'btc-e' => array(
            'url' => 'https://btc-e.com/api/3/ticker/btc_usd',
            'target' => 'btc_usd.last',
        ),*/
        'bitfinex' => array(
            'url' => 'https://api.bitfinex.com/v1/pubticker/BTCUSD',
            'target' => 'last_price',
        ),
        'kraken' => array(
            'url' => 'https://api.kraken.com/0/public/Ticker?pair=XXBTZUSD',
            'target' => 'result.XXBTZUSD.c.0',
        ),
        /*'huobi' => array(
            'url' => 'http://api.huobi.com/staticmarket/ticker_btc_json.js',
            'target' => 'ticker.last',
        ),*/
        'okcoin' => array(
            'url' => 'https://www.okcoin.com/api/v1/ticker.do?symbol=btc_usd',
            'target' => 'ticker.last',
        ),
    );

    public function __construct($aArgs) {

        //connect to twitter
        $this->oTwitter = new TwitterOAuth(CONSUMER_KEY, CONSUMER_SECRET, ACCESS_TOKEN, ACCESS_TOKEN_SECRET);
        $this->oTwitter->host = "https://api.twitter.com/1.1/";

        //make output visible in browser
        if (!empty($_SERVER['HTTP_HOST'])) {
            echo '<pre>';
        }

        //load args
        $this->parseArgs($aArgs);
    }

    private function parseArgs($aArgs) {

        $this->sUsername        = (!empty($aArgs['sUsername'])          ? $aArgs['sUsername']       : '');
        $this->sTweetFormat     = (!empty($aArgs['sTweetFormat'])       ? $aArgs['sTweetFormat']    : '');
        $this->sBirthdaySuffix  = (!empty($aArgs['sBirthdaySuffix'])    ? $aArgs['sBirthdaySuffix'] : '');
        $this->sLastRunFile     = (!empty($aArgs['sLastRunFile'])       ? $aArgs['sLastRunFile']    : strtolower(__CLASS__) . '.json');
        $this->sLogFile         = (!empty($aArgs['sLogFile'])           ? $aArgs['sLogFile']        : strtolower(__CLASS__) . '.log');
    }

    public function run() {

        if ($this->getIdentity()) {

            if ($lBtcPrice = $this->getBitcoinPrice()) {

                if ($sTweet = $this->createTweet($lBtcPrice)) {

                    $this->postTweet($sTweet);

                    $this->halt();
                }
            }
        }
    }

    private function getIdentity() {

        echo "Fetching identity..\n";

        if (!$this->sUsername) {
            $this->logger(2, 'No username');
            $this->halt('- No username! Set username when calling constructor.');
            return FALSE;
        }

        $oCurrentUser = $this->oTwitter->get('account/verify_credentials', array('include_entities' => FALSE, 'skip_status' => TRUE));

        if (is_object($oCurrentUser) && !empty($oCurrentUser->screen_name)) {
            if ($oCurrentUser->screen_name == $this->sUsername) {
                printf("- Allowed: @%s, continuing.\n\n", $oCurrentUser->screen_name);
            } else {
                $this->logger(2, sprintf('Authenticated username was unexpected: %s (expected: %s)', $oCurrentUser->screen_name, $this->sUsername));
                $this->halt(sprintf('- Not allowed: @%s (expected: %s), halting.', $oCurrentUser->screen_name, $this->sUsername));
                return FALSE;
            }
        } else {
            $this->logger(2, sprintf('Twitter API call failed: GET account/verify_credentials (%s)', $oCurrentUser->errors[0]->message));
            $this->halt(sprintf('- Call failed, halting. (%s)', $oCurrentUser->errors[0]->message));
            return FALSE;
        }

        return TRUE;
    }

    private function getBitcoinPrice() {

        echo "Getting BTC price in USD from all exchanges..\n";

        $oContext = stream_context_create(array(
            'http' => array('timeout' => 8)
        ));

        $aBtcPrices = array();
        foreach ($this->aBtcTickers as $sName => $aTicker) {

            printf("- Checking BTC price at %s..", $sName);
            $oResult = @json_decode(file_get_contents($aTicker['url'], FALSE, $oContext));

            $aSteps = explode('.', $aTicker['target']);
            $aResult = (array)$oResult;
            foreach ($aSteps as $sStep) {
                if (!empty($aResult[$sStep])) {
                    $aResult = (array)$aResult[$sStep];
                } else {
                    $this->logger(3, sprintf(' - Skipping exchange %s because json error', $sName));
                    $aResult[0] = FALSE;
                    break;
                }
            }

            if (!empty($aResult[0]) && is_numeric($aResult[0])) {
				echo (float)$aResult[0];
                $aBtcPrices[$sName] = (float)$aResult[0];
            }
			echo "\n";
        }

        return ($aBtcPrices ? array_sum($aBtcPrices) / count($aBtcPrices) : FALSE);
    }

    private function createTweet($lBtcPrice) {

        echo "Formatting tweet..\n";

        //check for valid number
        if (empty($lBtcPrice) || !is_numeric($lBtcPrice)) {
            $this->logger(3, sprintf('- argument is invalid (%s)', $lBtcPrice));
            return FALSE;
        }

        //fetch avg price from last run and calculate percent change
        $aLastRun = @json_decode(file_get_contents(MYPATH . '/' . $this->sLastRunFile), TRUE);
        if ($aLastRun) {

			$iChange = 100 * ($lBtcPrice - $aLastRun['last_price']) / $aLastRun['last_price'];
            if ($iChange < 1 && $iChange > -1 && $iChange != 0) {
                $iChange = number_format($iChange, 2);

                $iChange = ($iChange > 0 ? '+' : '') . $iChange;
            } else {
                $iChange = ($iChange > 0 ? '+' : '') . (int)$iChange;
            }
        } else {
            $iChange = 0;
        }

        //save current avg price for next run
        $aLastRun = array('last_price' => $lBtcPrice);
        file_put_contents(MYPATH . '/' . $this->sLastRunFile, json_encode($aLastRun));

        //construct tweet
        $aReplaces = array(
            ':worth' => number_format(10000 * $lBtcPrice),
            ':change' => ($iChange == 0 ? 'no change' : $iChange . '%'),
        );
        $sTweet = str_replace(array_keys($aReplaces), array_values($aReplaces), $this->sTweetFormat);

        if (date('n') == 5 && date('d') == 22) {
            //anniversary!
            $sTweet .= $this->sBirthdaySuffix;
        }

        return $sTweet;
    }

    private function postTweet($sTweet) {

        printf("- Posting: %s [%d]\n", $sTweet, strlen($sTweet));

        $oRet = $this->oTwitter->post('statuses/update', array('status' => $sTweet, 'trim_users' => TRUE));
        if (isset($oRet->errors)) {
            $this->logger(2, sprintf('Twitter API call failed: statuses/update (%s)', $oRet->errors[0]->message), array('tweet' => $sTweet));
            $this->halt('- Error: ' . $oRet->errors[0]->message . ' (code ' . $oRet->errors[0]->code . ')');
            return FALSE;
        }
    }

    private function halt($sMessage = '') {

        echo $sMessage . "\n\nDone!\n\n";
        return FALSE;
    }

    private function logger($iLevel, $sMessage, $aExtra = array()) {

        if ($iLevel > $this->iLogLevel) {
            return FALSE;
        }

        $sLogLine = "%s [%s] %s\n";
        $sTimestamp = date('Y-m-d H:i:s');

        switch($iLevel) {
        case 1:
            $sLevel = 'FATAL';
            break;
        case 2:
            $sLevel = 'ERROR';
            break;
        case 3:
            $sLevel = 'WARN';
            break;
        case 4:
        default:
            $sLevel = 'INFO';
            break;
        case 5:
            $sLevel = 'DEBUG';
            break;
        case 6:
            $sLevel = 'TRACE';
            break;
        }

		$aBacktrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
		TwitterLogger::write($this->sUsername, $sLevel, $sMessage, pathinfo($aBacktrace[0]['file'], PATHINFO_BASENAME), $aBacktrace[0]['line'], $aExtra);

        $iRet = file_put_contents(MYPATH . '/' . $this->sLogFile, sprintf($sLogLine, $sTimestamp, $sLevel, $sMessage), FILE_APPEND);

        if ($iRet === FALSE) {
            die($sTimestamp . ' [FATAL] Unable to write to logfile!');
        }
    }
}
