<?php
require_once('autoload.php');
require_once('josettefbookies.inc.php');

use Twitterbot\Lib\Config;
use Twitterbot\Lib\Logger;
use Twitterbot\Lib\Auth;
use Twitterbot\Lib\File;
use Twitterbot\Lib\Format;
use Twitterbot\Lib\Media;
use Twitterbot\Lib\Tweet;

class JosetteFbookies
{
    private $username;
    private $logger;

    public function __construct()
    {
        $this->username = 'JosetteFbookies';
        $this->logger = new Logger;
    }

    public function run($event = null)
    {
        $config = new Config;
        if ($config->load($this->username)) {

            if ((new Auth($config))->isUserAuthed($this->username)) {

                //determine folder we're going to pick from
                switch ($event) {

                    default:
                    case 'morning':
                        //mornings: 80% week day post, 20% coffee
                        if (rand(1, 100) < 80) {
                            //week day
                            switch (date('w')) {
                                case 0: $folder = 'zondag'; break;
                                case 1: $folder = 'maandag'; break;
                                case 2: $folder = 'dinsdag'; break;
                                case 3: $folder = 'woensdag'; break;
                                case 4: $folder = 'donderdag'; break;
                                case 5: $folder = 'vrijdag'; break;
                                case 6: $folder = 'zaterdag'; break;
                            }
                        } else {
                            $folder = 'koffie';
                        }
                        break;

                    case 'evening':
                        //evenings: divided between whatever's left, maybe add probabilities later
                        //@TODO: holiday-themed stuff, weather/season posts
                        //@TODO: 'overig' folder rename to hashtag-worthy, like 'inspirational' or w/e
                        $folders = [
                            'avond',
                            'welterusten',
                            'overig',
                        ];
                        $folder = $folders[array_rand($folders)];
                        break;
                }

                $filelist = (new File($config));
                if ($file = $filelist->get($folder)) {

                    $tweet = (new Format($config))
                        ->format($file);

                    if ($tweet) {
                        $this->logger->output('- attaching file to tweet..');

                        $mediaId = (new Media($config))
                            ->upload($file['filepath']);

                        if ($mediaId) {
                            (new Tweet($config))
                                ->set('aMediaIds', [$mediaId])
                                ->post($tweet);

                            $this->logger->output('Done!');
                        } else {
                            $this->logger->output('Failed to attach file.');
                        }
                    } else {
                        $this->logger->output('Failed to format tweet.');
                    }
                } else {
                    $this->logger->output('File index is empty.');
                }

                //rebuild index after tweeting, just in case
                $filelist->rebuildIndex();
            }
        }
    }
}

//pass 'morning' or 'evening' on CLI
$event = (!empty($argv[1]) ? $argv[1] : '');

(new JosetteFbookies)->run($event);
