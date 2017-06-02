<?php
/**
 * TODO:
 * v get random char, format, post - also replace unicode references in description
 * v emoji ranges 'additional emoji can also be found in the following' https://en.wikipedia.org/wiki/Emoji#Unicode_blocks
 * . emoji modifiers: fitzpatrick scale (skin tone) for certain ranges
 * . emoji variation selectors (VS16) https://en.wikipedia.org/wiki/Variation_Selectors_(Unicode_block)
 * - ZWJ-enabled (emoji) combinations?
 * - update database with newer unicode versions from https://github.com/unicode-table/unicode-table-data
 * - remove link from tweets to save space?
 * - don't post 'unnamed' characters
 */
require_once('autoload.php');
require_once('unicodetweet.inc.php');

use Twitterbot\Lib\Logger;
use Twitterbot\Lib\Config;
use Twitterbot\Lib\Auth;
use Twitterbot\Lib\Format;
use Twitterbot\Lib\Tweet;

(new UnicodeTweet)->run();

class UnicodeTweet
{
    public function __construct()
    {
        $this->sUsername = 'UnicodeTweet';
        $this->logger = new Logger;
    }

    public function run()
    {
        $this->oConfig = new Config;
        if ($this->oConfig->load($this->sUsername)) {
            if ((new Auth($this->oConfig))->isUserAuthed($this->sUsername)) {

                if ($aUnicode = $this->getRow()) {
                    $sTweet = (new Format($this->oConfig))->format((object)$aUnicode);

                    //replace unicode characters with hex html entities
                    $sTweet = preg_replace_callback('/[\x{80}-\x{10FFFF}]/u', function ($m) {
                        $char = current($m);
                        $utf = iconv('UTF-8', 'UCS-4', $char);
                        return sprintf('&#x%s;', ltrim(strtoupper(bin2hex($utf)), '0'));
                    }, $sTweet);

                    $this->logger->output($sTweet);
                    die();

                    if ((new Tweet($this->oConfig))->post($sTweet)) {
                        $this->logger->output('Done!');
                    }
                }
            }
        }
    }

    private function getRow()
    {
        $rows = json_decode(file_get_contents($this->oConfig->get('unicode-names')));

        if (date('w') == 0) {
            return $this->getSmileyRow($rows);
        } else {
            return $this->getRandomRow($rows);
        }
    }

    private function getRandomRow($rows)
    {
        $this->logger->output('Getting random record..');

        //filter out some crap
        $value = null;
        $description = null;
        for ($i = 0; $i < 1000; $i++) {

            //valid range is all (with exceptions above)
            $value = array_rand((array)$rows);
            $description = $rows->{$value};

            if (!empty($rows->{$value}) && !in_array($description, ['', '<Reserved>', '<Not a Character>'])) {
                break;
            }
        }
        if (empty($rows->{$value})) {
            $this->logger('- FAILED! unable to find character in %d rows after 1000 tries.', count($rows));
            die();
        }

        $this->logger->output('- picked U+%s %s', $value, $description);

        return [
            'description' => $description,
            'hex' => $value,
            'dec' => hexdec($value),
        ];
    }

    private function getSmileyRow($rows)
    {
        $this->logger->output('Getting smiley record!');

        //get official emoji ranges
        $aEmojis = $this->getEmojiRanges();

        $value = null;
        $description = null;
        //the official emoji ranges may be newer than the
        //full list of unicode I have, so find one that actually exists
        for ($i = 0; $i < 1000; $i++) {
            $this->logger->output('- getting random emoji');
            $key = array_rand($aEmojis);
            $value = str_pad(strtoupper(dechex($aEmojis[$key])), 4, '0');
            $description = $rows->{$value};

            if (!empty($rows->{$value}) && !in_array($description, ['', '<Reserved>', '<Not a Character>'])) {
                break;
            }
        }
        if (empty($rows->{$value})) {
            $this->logger('- FAILED! unable to find emoji in %d rows after 1000 tries.', count($rows));
            die();
        }

        $fitzpatrick = false;
        $fitzpatrickKey = false;

        //apply fitzpatrick scale (skintone)
        $aFitzpatrickRanges = $this->getFitzpatrickRanges();
        if (in_array($aEmojis[$key], $aFitzpatrickRanges)) {

            if (rand(1, 2) == 1) {
                //50% chance of applying fitzpatrick modifier
                $aFitzpatrick = [
                    'type 1-2' => 0x1F3FB,
                    'type 3' => 0x1F3FC,
                    'type 4' => 0x1F3FD,
                    'type 5' => 0x1F3FE,
                    'type 6' => 0x1F3FF,
                ];

                $fitzpatrickKey = array_rand($aFitzpatrick);
                $fitzpatrick = $aFitzpatrick[$fitzpatrickKey];
            }
        }

        $variation = false;
        $variationKey = false;

        //apply variation selector
        $aVariationRanges = $this->getVariationRanges();
        if (in_array($aEmojis[$key], $aVariationRanges)) {

            if (rand(1, 2) == 1) {
                //50% chance of applying variation modifier (VS15 or VS16)
                $aVariation = [
                    'VS15' => 0xFE0E,
                    'VS16' => 0xFE0F,
                ];

                $variationKey = array_rand($aVariation);
                $variation = $aVariation[$variationKey];
            }
        }

        $this->logger->output('- picked U+%s %s %s %s',
            $value,
            $description,
            $fitzpatrickKey ? sprintf('with Fitzpatrick scale %s', $fitzpatrickKey) : '',
            $variationKey ? sprintf('with variation %s', $variationKey) : ''
        );

        return [
            'description' => $description,
            'hex' => $value,
            'dec' => hexdec($value),
            'modifier1' => $fitzpatrickKey ? $fitzpatrick : '',
            'modifier2' => $variationKey ? $variation : '',
        ];
    }

    private function getFitzpatrickRanges()
    {
        $aRanges = [
            //Supplemental Symbols and Pictographs
            ['start' => 0x1F918, 'end' => 0x1F91C],
            ['start' => 0x1F91E],
            ['start' => 0x1F926],
            ['start' => 0x1F930],
            ['start' => 0x1F933, 'end' => 0x1F939],
            ['start' => 0x1F93D],
            ['start' => 0x1F93E],

            //Miscellaneous Symbols and Pictographs
            ['start' => 0x1F385],
            ['start' => 0x1F3C2, 'end' => 0x1F3C4],
            ['start' => 0x1F3C7],
            ['start' => 0x1F3CA, 'end' => 0x1F3CC],
            ['start' => 0x1F442, 'end' => 0x1F443],

            ['start' => 0x1F446, 'end' => 0x1F450],
            ['start' => 0x1F466, 'end' => 0x1F469],
            ['start' => 0x1F46E],
            ['start' => 0x1F470, 'end' => 0x1F478],
            ['start' => 0x1F47C],
            ['start' => 0x1F481, 'end' => 0x1F483],
            ['start' => 0x1F485, 'end' => 0x1F487],
            ['start' => 0x1F4AA],
            ['start' => 0x1F574, 'end' => 0x1F575],
            ['start' => 0x1F57A],
            ['start' => 0x1F590],
            ['start' => 0x1F595, 'end' => 0x1F596],

            //Emoticons
            ['start' => 0x1F645, 'end' => 0x1F647],
            ['start' => 0x1F64B, 'end' => 0x1F64F],

            //Transport and Map Symbols
            ['start' => 0x1F6A3],
            ['start' => 0x1F6B4, 'end' => 0x1F6B6],
            ['start' => 0x1F6C0],
            ['start' => 0x1F6CC],

            //Miscellaneous Symbols
            ['start' => 0x261D],
            ['start' => 0x26F9],

            //Dingbats
            ['start' => 0x270A, 'end' => 0x270D],
        ];

        return $this->convertRangesToCodepointArray($aRanges);
    }

    private function getVariationRanges()
    {
        $aRanges = [
            //Miscellaneous Symbols and Pictographs
            ['start' => 0x1F321],
            ['start' => 0x1F324, 'end' => 0x1F32C],
            ['start' => 0x1F336],
            ['start' => 0x1F37D],
            ['start' => 0x1F396, 'end' => 0x1F397],
            ['start' => 0x1F399, 'end' => 0x1F39B],
            ['start' => 0x1F39E, 'end' => 0x1F39F],
            ['start' => 0x1F3CB, 'end' => 0x1F3CE],
            ['start' => 0x1F3D4, 'end' => 0x1F3DF],
            ['start' => 0x1F3F3],
            ['start' => 0x1F3F5],
            ['start' => 0x1F3F7],
            ['start' => 0x1F43F],
            ['start' => 0x1F441],
            ['start' => 0x1F4FD],
            ['start' => 0x1F549, 'end' => 0x1F54A],
            ['start' => 0x1F56F, 'end' => 0x1F570],
            ['start' => 0x1F573, 'end' => 0x1F579],
            ['start' => 0x1F587],
            ['start' => 0x1F58A, 'end' => 0x1F58D],
            ['start' => 0x1F590],
            ['start' => 0x1F5A5],
            ['start' => 0x1F5A8],
            ['start' => 0x1F5B1, 'end' => 0x1F5B2],
            ['start' => 0x1F5BC],
            ['start' => 0x1F5C2, 'end' => 0x1F5C4],
            ['start' => 0x1F5D1, 'end' => 0x1F5D3],
            ['start' => 0x1F5DC, 'end' => 0x1F5DE],
            ['start' => 0x1F5E1],
            ['start' => 0x1F5E3],
            ['start' => 0x1F5E8],
            ['start' => 0x1F5EF],
            ['start' => 0x1F5F3],
            ['start' => 0x1F5FA],

            //Transport and Map Symbols
            ['start' => 0x1F6CB],
            ['start' => 0x1F6CD, 'end' => 0x1F6CF],
            ['start' => 0x1F6E0, 'end' => 0x1F6E6],
            ['start' => 0x1F6F0],
            ['start' => 0x1F6F3],

            //Miscellaneous Symbols
            ['start' => 0x2600, 'end' => 0x2604],
            ['start' => 0x260E],
            ['start' => 0x2611],
            ['start' => 0x2614, 'end' => 0x2615],
            ['start' => 0x2618],
            ['start' => 0x261D],
            ['start' => 0x2620],

            ['start' => 0x2622, 'end' => 0x2623],
            ['start' => 0x2626],
            ['start' => 0x262A],
            ['start' => 0x262E, 'end' => 0x262F],
            ['start' => 0x2638, 'end' => 0x263A],
            ['start' => 0x2640],
            ['start' => 0x2642],
            ['start' => 0x2648, 'end' => 0x2653],
            ['start' => 0x2660],

            ['start' => 0x2663],
            ['start' => 0x2665, 'end' => 0x2666],
            ['start' => 0x2668],
            ['start' => 0x267B],
            ['start' => 0x267F],
            ['start' => 0x2692, 'end' => 0x2697],
            ['start' => 0x2699],
            ['start' => 0x269B, 'end' => 0x269C],
            ['start' => 0x26A0, 'end' => 0x26A1],
            ['start' => 0x26AA, 'end' => 0x26AB],
            ['start' => 0x26B0, 'end' => 0x26B1],
            ['start' => 0x26BD, 'end' => 0x26BE],
            ['start' => 0x26C4, 'end' => 0x26C5],
            ['start' => 0x26C8],
            ['start' => 0x26CF],
            ['start' => 0x26D1],
            ['start' => 0x26D3, 'end' => 0x26D3],
            ['start' => 0x26E9, 'end' => 0x26EA],
            ['start' => 0x26F0, 'end' => 0x26F5],
            ['start' => 0x26F7, 'end' => 0x26FA],
            ['start' => 0x26FD],

            //Dingbats
            ['start' => 0x2702],
            ['start' => 0x2708, 'end' => 0x2709],
            ['start' => 0x270C, 'end' => 0x270D],
            ['start' => 0x270F],
            ['start' => 0x2712],
            ['start' => 0x2714],
            ['start' => 0x2716],
            ['start' => 0x271D],
            ['start' => 0x2721],
            ['start' => 0x2733, 'end' => 0x2734],
            ['start' => 0x2744],
            ['start' => 0x2747],
            ['start' => 0x2757],
            ['start' => 0x2763, 'end' => 0x2764],
            ['start' => 0x27A1],
        ];

        return $this->convertRangesToCodepointArray($aRanges);
    }

    private function getEmojiRanges()
    {
        $aRanges = [
            //Miscelaneous Symbols and Pictographs
            ['start' => 0x1F300, 'end' => 0x1F321],
            ['start' => 0x1F324, 'end' => 0x1F393],
            ['start' => 0x1F396, 'end' => 0x1F397],
            ['start' => 0x1F399, 'end' => 0x1F39B],
            ['start' => 0x1F39E, 'end' => 0x1F3F0],
            ['start' => 0x1F3F3, 'end' => 0x1F3F5],
            ['start' => 0x1F3F7, 'end' => 0x1F4FD],
            ['start' => 0x1F4FF, 'end' => 0x1F53D],
            ['start' => 0x1F549, 'end' => 0x1F54E],
            ['start' => 0x1F550, 'end' => 0x1F567],
            ['start' => 0x1F56F, 'end' => 0x1F570],
            ['start' => 0x1F573, 'end' => 0x1F57A],
            ['start' => 0x1F587],
            ['start' => 0x1F58A, 'end' => 0x1F58D],
            ['start' => 0x1F590],
            ['start' => 0x1F595, 'end' => 0x1F596],
            ['start' => 0x1F5A4, 'end' => 0x1F5A5],
            ['start' => 0x1F5A8],
            ['start' => 0x1F5B1, 'end' => 0x1F5B2],
            ['start' => 0x1F5BC],
            ['start' => 0x1F5C2, 'end' => 0x1F5C4],
            ['start' => 0x1F5D1, 'end' => 0x1F5D3],
            ['start' => 0x1F5DC, 'end' => 0x1F5D],
            ['start' => 0x1F5E1],
            ['start' => 0x1F5E3],
            ['start' => 0x1F5E8],
            ['start' => 0x1F5EF],
            ['start' => 0x1F5F3],
            ['start' => 0x1F5FA, 'end' => 0x1F5FF],

            //Supplemental Symbols and Pictographs
            ['start' => 0x1F910, 'end' => 0x1F91E],
            ['start' => 0x1F920, 'end' => 0x1F927],
            ['start' => 0x1F930],
            ['start' => 0x1F933, 'end' => 0x1F93A],
            ['start' => 0x1F93C, 'end' => 0x1F93E],
            ['start' => 0x1F940, 'end' => 0x1F945],
            ['start' => 0x1F947, 'end' => 0x1F94B],
            ['start' => 0x1F950, 'end' => 0x1F95E],
            ['start' => 0x1F980, 'end' => 0x1F991],
            ['start' => 0x1F9C0],

            //Emoticons
            ['start' => 0x1F600, 'end' => 0x1F64F],

            //Transport and Map Symbols
            ['start' => 0x1F680, 'end' => 0x1F6D2],
            ['start' => 0x1F6E0, 'end' => 0x1F6EC],
            ['start' => 0x1F6F0, 'end' => 0x1F6F6],

            //Miscellaneous Symbols
            ['start' => 0x2600, 'end' => 0x2604],
            ['start' => 0x260E],
            ['start' => 0x2611],
            ['start' => 0x2614, 'end' => 0x2615],
            ['start' => 0x2618],
            ['start' => 0x261D],
            ['start' => 0x2620],
            ['start' => 0x2622, 'end' => 0x2623],
            ['start' => 0x2626],
            ['start' => 0x262A],
            ['start' => 0x262E, 'end' => 0x262F],
            ['start' => 0x2638, 'end' => 0x263A],
            ['start' => 0x2640],
            ['start' => 0x2642],
            ['start' => 0x2648, 'end' => 0x2653],
            ['start' => 0x2660],
            ['start' => 0x2663],
            ['start' => 0x2665, 'end' => 0x2666],
            ['start' => 0x2668],
            ['start' => 0x267B],
            ['start' => 0x267F],
            ['start' => 0x2692, 'end' => 0x2697],
            ['start' => 0x2699],
            ['start' => 0x269B, 'end' => 0x269C],
            ['start' => 0x26A0, 'end' => 0x26A1],
            ['start' => 0x26AA, 'end' => 0x26AB],
            ['start' => 0x26B0, 'end' => 0x26B1],
            ['start' => 0x26BD, 'end' => 0x26BE],
            ['start' => 0x26C4, 'end' => 0x26C5],
            ['start' => 0x26C8],
            ['start' => 0x26CE, 'end' => 0x26CF],
            ['start' => 0x26D1],
            ['start' => 0x26D3, 'end' => 0x26D4],
            ['start' => 0x26E9, 'end' => 0x26EA],
            ['start' => 0x26F0, 'end' => 0x26F5],
            ['start' => 0x26F7, 'end' => 0x26FA],
            ['start' => 0x26FD],

            //Dingbats
            ['start' => 0x2702],
            ['start' => 0x2705],
            ['start' => 0x2708, 'end' => 0x270D],
            ['start' => 0x270F],
            ['start' => 0x2712],
            ['start' => 0x2714],
            ['start' => 0x2716],
            ['start' => 0x271D],
            ['start' => 0x2721],
            ['start' => 0x2728],
            ['start' => 0x2733, 'end' => 0x2734],
            ['start' => 0x2744],
            ['start' => 0x2747],
            ['start' => 0x274C],
            ['start' => 0x274E],
            ['start' => 0x2753, 'end' => 0x2755],
            ['start' => 0x2757],
            ['start' => 0x2763, 'end' => 0x2764],
            ['start' => 0x2795, 'end' => 0x2797],
            ['start' => 0x27A1],
            ['start' => 0x27B0],
            ['start' => 0x27BF],
        ];

        return $this->convertRangesToCodepointArray($aRanges);
    }

    private function convertRangesToCodepointArray($aRanges)
    {
        $aCodepoints = [];
        foreach ($aRanges as $aRange) {
            if (isset($aRange['end'])) {
                for ($i = $aRange['start']; $i <= $aRange['end']; $i++) {
                    $aCodepoints[] = $i;
                }
            } else {
                $aCodepoints[] = $aRange['start'];
            }
        }

        return $aCodepoints;
    }
}
