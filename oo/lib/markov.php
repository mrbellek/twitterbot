<?php
namespace Twitterbot\Lib;

/**
 * Markov class to generate Markov chains from body of text in given file
 */
class Markov extends Base
{
    /**
     * Generate Markov chains from input file with body of text
     *
     * @param string $sInputFile
     *
     * @return array
     */
    public function generateChains($sInputFile)
    {
        $this->logger->output('Generating Markov chains');

        if (!$this->sInputFile || filesize($this->sInputFile) == 0) {
            $this->logger->write(2, 'No input file specified.');
            $this->logger->output('- No input file specified, halting.');

            return false;
        }

        $lStart = microtime(true);
        $sInput = implode(' ', file($this->sInputFile, FILE_IGNORE_NEW_LINES));
        $this->logger->output('- Read input file %s (%d bytes in %.3fs)..', $this->sInputFile, filesize($this->sInputFile), microtime(true) - $lStart);

        $lStart = microtime(true);
        $aWords = str_word_count($sInput, 1, '\'"-,.;:0123456789%?!');

        $aMarkovChains = array();
        foreach ($aWords as $i => $sWord) {
            if (!empty($aWords[$i + 2])) {
                $aMarkovChains[$sWord . ' ' . $aWords[$i + 1]][] = $aWords[$i + 2];
            }
        }
        $this->logger->output('- done, generated %d chains in %.3f seconds', count($aMarkovChains), microtime(true) - $lStart);
        $this->aMarkovChains = $aMarkovChains;

        return $aMarkovChains;
    }

    /**
     * Load Markov chains from json file into memory
     *
     * @param string $sInputFile
     *
     * @return void
     */
    public function loadChains($sInputFile)
    {
        $this->logger->output('Loading Markov chains from file %s', $sInputFile);
        if (!is_file($sInputFile)) {
            $this->logger->output('- Error loading from %s: file does not exist', $sInputFile);

            return false;
        }

        $lStart = microtime(true);
        $this->aMarkovChains = @json_decode(file_get_contents($sInputFile));

        if ($iJsonErr = json_last_error()) {
            $this->logger->output('- error loading from %s: json_decode error %d', $sInputFile, $iJsonErr);

            return false;
        }

        $this->logger->output('- done, loaded %d chains in %d seconds', count($this->aMarkovChains), microtime(true) - $lStart);

        return true;
    }

    /**
     * Generate tweet from currently loaded Markov chains
     *
     * @return string
     */
    public function generateTweet()
    {
        if (!$this->aMarkovChains) {
            $this->logger->output('No Markov chains present, load or generate them first.');

            return false;
        }

        $this->logger->output('Generating tweet..');

        //TODO: pick key with capital letter first?
        srand();
        mt_srand();
        $sKey = array_rand($this->aMarkovChains); //get random start key
        $sNewTweet = $sKey; //new sentence starts with this key

        while (array_key_exists($sKey, $this->aMarkovChains)) {
            //get next word to add to sentence (random, based on key)
            $sNextWord = $this->aMarkovChains[$sKey][array_rand($this->aMarkovChains[$sKey])];

            //remove first word from key, add new word to it to create next key
            $aKey = explode(' ', $sKey);
            array_shift($aKey);
            $aKey[] = $sNextWord;
            $sKey = implode(' ', $aKey);

            //add next word to tweet
            if (strlen($sNewTweet . ' ' . $sNextWord) <= 280) {
                $sNewTweet .= ' ' . $sNextWord;
            } else {
                break;
            }
        }

        return html_entity_decode($sNewTweet);
    }
}
