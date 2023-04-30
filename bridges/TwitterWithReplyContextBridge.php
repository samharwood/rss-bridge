<?php

class TwitterWithReplyContextBridge extends BridgeAbstract
{
    const NAME = 'Twitter with Reply Context Bridge';
    const URI = 'https://twitter.com/';
    const LINK_URI = 'https://nitter.it/';
    const API_URI = 'https://api.twitter.com';
    const GUEST_TOKEN_USES = 100;
    const GUEST_TOKEN_EXPIRY = 10800; // 3hrs
    const CACHE_TIMEOUT = 300; // 5min
    const DESCRIPTION = 'returns tweets and the tweet being replied to for context';
    const MAINTAINER = 'samharwood';
    const PARAMETERS = [
        'global' => [
            'nopic' => [
                'name' => 'Hide profile pictures',
                'type' => 'checkbox',
                'title' => 'Activate to hide profile pictures in content'
            ],
            'noimg' => [
                'name' => 'Hide images in tweets',
                'type' => 'checkbox',
                'title' => 'Activate to hide images in tweets'
            ],
            'noimgscaling' => [
                'name' => 'Disable image scaling',
                'type' => 'checkbox',
                'title' => 'Activate to disable image scaling in tweets (keeps original image)'
            ]
        ],
        'By keyword or hashtag' => [
            'q' => [
                'name' => 'Keyword or #hashtag',
                'required' => true,
                'exampleValue' => 'rss-bridge OR rssbridge',
                'title' => <<<EOD
* To search for multiple words (must contain all of these words), put a space between them.

Example: `rss-bridge release`.

* To search for multiple words (contains any of these words), put "OR" between them.

Example: `rss-bridge OR rssbridge`.

* To search for an exact phrase (including whitespace), put double-quotes around them.

Example: `"rss-bridge release"`

* If you want to search for anything **but** a specific word, put a hyphen before it.

Example: `rss-bridge -release` (ignores "release")

* Of course, this also works for hashtags.

Example: `#rss-bridge OR #rssbridge`

* And you can combine them in any shape or form you like.

Example: `#rss-bridge OR #rssbridge -release`
EOD
            ]
        ],
        'By username' => [
            'u' => [
                'name' => 'username',
                'required' => true,
                'exampleValue' => 'sebsauvage',
                'title' => 'Insert a user name'
            ],
            'norep' => [
                'name' => 'Without replies',
                'type' => 'checkbox',
                'title' => 'Only return initial tweets'
            ],
            'noreplycontext' => [
                'name' => 'Without reply context',
                'type' => 'checkbox',
                'title' => 'Disables fetching and showing the tweet being replied to'
            ],
            'noretweet' => [
                'name' => 'Without retweets',
                'required' => false,
                'type' => 'checkbox',
                'title' => 'Hide retweets'
            ],
            'nopinned' => [
                'name' => 'Without pinned tweet',
                'required' => false,
                'type' => 'checkbox',
                'title' => 'Hide pinned tweet'
            ]
        ],
        'By list' => [
            'user' => [
                'name' => 'User',
                'required' => true,
                'exampleValue' => 'Scobleizer',
                'title' => 'Insert a user name'
            ],
            'list' => [
                'name' => 'List',
                'required' => true,
                'exampleValue' => 'Tech-News',
                'title' => 'Insert the list name'
            ],
            'filter' => [
                'name' => 'Filter',
                'exampleValue' => '#rss-bridge',
                'required' => false,
                'title' => 'Specify term to search for'
            ]
        ],
        'By list ID' => [
            'listid' => [
                'name' => 'List ID',
                'exampleValue' => '31748',
                'required' => true,
                'title' => 'Insert the list id'
            ],
            'filter' => [
                'name' => 'Filter',
                'exampleValue' => '#rss-bridge',
                'required' => false,
                'title' => 'Specify term to search for'
            ]
        ]
    ];

    private $apiKey     = null;
    private $guestToken = null;
    private $authHeader = [];

    public function detectParameters($url)
    {
        $params = [];

        // By keyword or hashtag (search)
        $regex = '/^(https?:\/\/)?(www\.)?twitter\.com\/search.*(\?|&)q=([^\/&?\n]+)/';
        if (preg_match($regex, $url, $matches) > 0) {
            $params['q'] = urldecode($matches[4]);
            return $params;
        }

        // By hashtag
        $regex = '/^(https?:\/\/)?(www\.)?twitter\.com\/hashtag\/([^\/?\n]+)/';
        if (preg_match($regex, $url, $matches) > 0) {
            $params['q'] = urldecode($matches[3]);
            return $params;
        }

        // By list
        $regex = '/^(https?:\/\/)?(www\.)?twitter\.com\/([^\/?\n]+)\/lists\/([^\/?\n]+)/';
        if (preg_match($regex, $url, $matches) > 0) {
            $params['user'] = urldecode($matches[3]);
            $params['list'] = urldecode($matches[4]);
            return $params;
        }

        // By username
        $regex = '/^(https?:\/\/)?(www\.)?twitter\.com\/([^\/?\n]+)/';
        if (preg_match($regex, $url, $matches) > 0) {
            $params['u'] = urldecode($matches[3]);
            return $params;
        }

        return null;
    }

    public function getName()
    {
        switch ($this->queriedContext) {
            case 'By keyword or hashtag':
                $specific = 'search ';
                $param = 'q';
                break;
            case 'By username':
                $specific = '@';
                $param = 'u';
                break;
            case 'By list':
                return $this->getInput('list') . ' - Twitter list by ' . $this->getInput('user');
            case 'By list ID':
                return 'Twitter List #' . $this->getInput('listid');
            default:
                return parent::getName();
        }
        return 'Twitter ' . $specific . $this->getInput($param);
    }

    public function getURI()
    {
        switch ($this->queriedContext) {
            case 'By keyword or hashtag':
                return self::URI
            . 'search?q='
            . urlencode($this->getInput('q'))
            . '&f=tweets';
            case 'By username':
                return self::URI
            . urlencode($this->getInput('u'));
            // Always return without replies!
            // . ($this->getInput('norep') ? '' : '/with_replies');
            case 'By list':
                return self::URI
            . urlencode($this->getInput('user'))
            . '/lists/'
            . str_replace(' ', '-', strtolower($this->getInput('list')));
            case 'By list ID':
                return self::URI
            . 'i/lists/'
            . urlencode($this->getInput('listid'));
            default:
                return parent::getURI();
        }
    }

    public function collectData()
    {
        // $data will contain an array of all found tweets (unfiltered)
        $data = null;
        // Contains user data (when in by username context)
        $user = null;
        // Array of all found tweets
        $tweets = [];

        // Get authentication information
        $this->getApiKey();

        // Try to get all tweets
        switch ($this->queriedContext) {
            case 'By username':
                $user = $this->makeApiCall('/1.1/users/show.json', ['screen_name' => $this->getInput('u')]);
                if (!$user) {
                    returnServerError('Requested username can\'t be found.');
                }

                $params = [
                'user_id'       => $user->id_str,
                'tweet_mode'    => 'extended'
                ];

                $data = $this->makeApiCall('/1.1/statuses/user_timeline.json', $params);
                break;

            case 'By keyword or hashtag':
                $params = [
                'q'                 => urlencode($this->getInput('q')),
                'tweet_mode'        => 'extended',
                'tweet_search_mode' => 'live',
                ];

                $data = $this->makeApiCall('/1.1/search/tweets.json', $params)->statuses;
                break;

            case 'By list':
                $params = [
                'slug'              => strtolower($this->getInput('list')),
                'owner_screen_name' => strtolower($this->getInput('user')),
                'tweet_mode'        => 'extended',
                ];

                $data = $this->makeApiCall('/1.1/lists/statuses.json', $params);
                break;

            case 'By list ID':
                $params = [
                'list_id'           => $this->getInput('listid'),
                'tweet_mode'        => 'extended',
                ];

                $data = $this->makeApiCall('/1.1/lists/statuses.json', $params);
                break;

            default:
                returnServerError('Invalid query context !');
        }

        if (!$data) {
            switch ($this->queriedContext) {
                case 'By keyword or hashtag':
                    returnServerError('twitter: No results for this query.');
                    // fall-through
                case 'By username':
                    returnServerError('Requested username can\'t be found.');
                    // fall-through
                case 'By list':
                    returnServerError('Requested username or list can\'t be found');
            }
        }

        // Filter out unwanted tweets
        foreach ($data as $tweet) {
            // Filter out retweets to remove possible duplicates of original tweet
            switch ($this->queriedContext) {
                case 'By keyword or hashtag':
                    if (isset($tweet->retweeted_status) && substr($tweet->full_text, 0, 4) === 'RT @') {
                        continue 2;
                    }
                    break;
            }
            $tweets[] = $tweet;
        }

        // Get set of tweets being replied to
        $hideReplyContext = $this->getInput('noreplycontext');        
        if (!$hideReplyContext) { 
            $replyids = '';
            foreach ($tweets as $tweet) {
                $replyids .= $tweet->in_reply_to_status_id_str . ",";
            }
            $params = [
                'id'            => $replyids,
                'tweet_mode'    => 'extended'
                ];
            $replytos = $this->makeApiCall('/1.1/statuses/lookup.json', $params);
        }

        $hidePinned = $this->getInput('nopinned');
        if ($hidePinned) {
            $pinnedTweetId = null;
            if ($user && $user->pinned_tweet_ids_str) {
                $pinnedTweetId = $user->pinned_tweet_ids_str;
            }
        }

        foreach ($tweets as $tweet) {
            // Skip own Retweets...
            if (isset($tweet->retweeted_status) && $tweet->retweeted_status->user->id_str === $tweet->user->id_str) {
                continue;
            }

            // Skip pinned tweet
            if ($hidePinned && $tweet->id_str === $pinnedTweetId) {
                continue;
            }

            switch ($this->queriedContext) {
                case 'By username':
                    if ($this->getInput('norep') && isset($tweet->in_reply_to_status_id)) {
                        continue 2;
                    }
                    break;
            }

            $item = [];


            $item['username']  = $tweet->user->screen_name;
            $item['fullname']  = $tweet->user->name;
            $item['avatar']    = $tweet->user->profile_image_url_https;
            $item['timestamp'] = $tweet->created_at;
            $item['id']        = $tweet->id_str;
            $item['uri']       = self::LINK_URI . $item['username'] . '/status/' . $item['id'];
            $item['author']    = $item['fullname']
                                . ' (@'
                                . $item['username'] . ')';

            if (isset($tweet->retweeted_status)) {
                // Tweet is a Retweet, so redirect id, uri to retweet
                $item['id']        = $tweet->retweeted_status->id_str;
                $item['uri']       = self::LINK_URI . $tweet->retweeted_status->user->screen_name . '/status/' . $item['id'];
            }

            // For replies, match the tweet they were replying to
            $in_reply_to_tweet = null;
            if($tweet->in_reply_to_status_id_str != null) {
                foreach($replytos as $replyto) {
                    if($replyto->id_str == $tweet->in_reply_to_status_id_str) {
                        $in_reply_to_tweet = $replyto; 
                    }
                }
            }

            $cleanedTweet = $this->cleanTweet($tweet);
            $cleanedReplyTo = $this->cleanTweet($in_reply_to_tweet);
            
            // generate the title
            $title = strip_tags($tweet->full_text);
            $title = substr($title,0,100);
			if(strlen($title) >= 100) { $title .= "..."; }

            $item['title'] = $title;

            // generate the tweet and reply HTML
            $avatar_image = $this->avatar_html($tweet);
            $avatar_image_replyto = $this->avatar_html($in_reply_to_tweet);
            $media_embed = $this->media_html($tweet, $item);
            $media_embed_replyto = $this->media_html($in_reply_to_tweet, $item);
            
            
            switch ($this->queriedContext) {
                case 'By list':
                case 'By list ID':
                    // Check if filter applies to list (using raw content)
                    if ($this->getInput('filter')) {
                        if (stripos($cleanedTweet, $this->getInput('filter')) === false) {
                            continue 2; // switch + for-loop!
                        }
                    }
                    break;
                case 'By username':
                    if ($this->getInput('noretweet') && strtolower($item['username']) != strtolower($this->getInput('u'))) {
                        continue 2; // switch + for-loop!
                    }
                    break;
                default:
            }

            // Layout the HTML
            $item['content'] = '';
            
            if ($in_reply_to_tweet != null) {
            $item['content'] .= <<<EOD
<div style="display: block; vertical-align: top;">
    {$avatar_image_replyto}
</div>
<div style="display: block; vertical-align: top;">
    <p>{$cleanedReplyTo}</p>
</div>
<div style="display: block; vertical-align: top;">
    <p>{$media_embed_replyto}</p>
</div>
EOD;
            }

            $item['content'] .= <<<EOD
<div style="display: block; vertical-align: top;">
	{$avatar_image}
</div>
<div style="display: block; vertical-align: top;">
	<p>{$cleanedTweet}</p>
</div>
<div style="display: block; vertical-align: top;">
	<p>{$media_embed}</p>
</div>
EOD;

            // put out
            $this->items[] = $item;
        }

        usort($this->items, ['TwitterWithReplyContextBridge', 'compareTweetId']);
    }

    private function cleanTweet($tweet)
    {
        // Convert plain text URLs into HTML hyperlinks
        if($tweet == null) return '';

        if (isset($tweet->retweeted_status)) { 
            $tweet = $tweet->retweeted_status;
            $fulltext = "RT <a href='" . self::LINK_URI . $tweet->user->screen_name . "'>@" . $tweet->user->screen_name . "</a>: " .  $tweet->full_text;
        } else {
            $fulltext = $tweet->full_text;
        }
        
        $cleanedTweet = $fulltext;

        $foundUrls = false;

        if (isset($tweet->entities->media)) {
            foreach ($tweet->entities->media as $media) {
                $cleanedTweet = str_replace(
                    $media->url,
                    '<a href="' . $media->expanded_url . '">' . $media->display_url . '</a>',
                    $cleanedTweet
                );
                $foundUrls = true;
            }
        }
        if (isset($tweet->entities->urls)) {
            foreach ($tweet->entities->urls as $url) {
                $cleanedTweet = str_replace(
                    $url->url,
                    '<a href="' . $url->expanded_url . '">' . $url->display_url . '</a>',
                    $cleanedTweet
                );
                $foundUrls = true;
            }
        }
        if ($foundUrls === false) {
            // fallback to regex'es
            $reg_ex = '/(http|https|ftp|ftps)\:\/\/[a-zA-Z0-9\-\.]+\.[a-zA-Z]{2,3}(\/\S*)?/';
            if (preg_match($reg_ex, $tweet->full_text, $url)) {
                $cleanedTweet = preg_replace(
                    $reg_ex,
                    "<a href='{$url[0]}' target='_blank'>{$url[0]}</a> ",
                    $cleanedTweet
                );
            }
        }
        foreach ($tweet->entities->hashtags as $hashtag) {
            $cleanedTweet = str_replace('#' . $hashtag->text, "<a href='" . self::LINK_URI . "/search?q=%23" . $hashtag->text . "'>#" . $hashtag->text . "</a>", $cleanedTweet);
        }
        foreach ($tweet->entities->user_mentions as $mentions) {
            $cleanedTweet = str_replace('@' . $mentions->screen_name, "<a href='" . self::LINK_URI . $mentions->screen_name . "'>@" . $mentions->screen_name . "</a>", $cleanedTweet);
        }
        return $cleanedTweet;
    }

    private function avatar_html($tweet)
    {
        if($tweet == null) return '';

        // Add avatar
        $uri = self::LINK_URI;
        $picture_html = '';
        $hidePictures = $this->getInput('nopic');
        if (!$hidePictures) {

            $picture_html = <<<EOD
    <a href="{$uri}{$tweet->user->screen_name}">
    <img
    alt="{$tweet->user->screen_name}"
    src="{$tweet->user->profile_image_url_https}"
    title="{$tweet->user->name}" />
    </a>
    <a href="{$uri}{$tweet->user->screen_name}">{$tweet->user->name} (@{$tweet->user->screen_name}):</a>
    EOD;

            if ($tweet->retweeted_status) {
                $picture_html .= <<<EOD
             / 
            <a href="{$uri}{$tweet->retweeted_status->user->screen_name}">
            <img
            alt="{$tweet->retweeted_status->user->screen_name}"
            src="{$tweet->retweeted_status->user->profile_image_url_https}"
            title="{$tweet->retweeted_status->user->name}" />
            </a>
            <a href="{$uri}{$tweet->retweeted_status->user->screen_name}">{$tweet->retweeted_status->user->name} (@{$tweet->retweeted_status->user->screen_name}):</a>
            EOD;
            }

        }
        return $picture_html;
    }

    private function media_html($tweet, $item)
    {
        if($tweet == null) return '';

        if (isset($tweet->retweeted_status)) $tweet = $tweet->retweeted_status;

        // Get images
        $media_html = '';
        if (isset($tweet->extended_entities->media) && !$this->getInput('noimg')) {
            foreach ($tweet->extended_entities->media as $media) {
                switch ($media->type) {
                    case 'photo':
                        $image = $media->media_url_https . '?name=orig';
                        $display_image = $media->media_url_https;
                        // add enclosures
                        $item['enclosures'][] = $image;

                        $media_html .= <<<EOD
    <a href="{$image}">
    <img
    style="align:top; max-width:558px; border:1px solid black;"
    referrerpolicy="no-referrer"
    src="{$display_image}" />
    </a>
    EOD;
                        break;
                    case 'video':
                    case 'animated_gif':
                        if (isset($media->video_info)) {
                            $link = $media->expanded_url;
                            $poster = $media->media_url_https;
                            $video = null;
                            $maxBitrate = -1;
                            foreach ($media->video_info->variants as $variant) {
                                $bitRate = $variant->bitrate ?? -100;
                                if ($bitRate > $maxBitrate) {
                                    $maxBitrate = $bitRate;
                                    $video = $variant->url;
                                }
                            }
                            if (!is_null($video)) {
                                // add enclosures
                                $item['enclosures'][] = $video;
                                $item['enclosures'][] = $poster;

                                $media_html .= <<<EOD
    <a href="{$link}">Video</a>
    <video controls 
    style="align:top; max-width:558px; border:1px solid black;"
    referrerpolicy="no-referrer"
    src="{$video}" poster="{$poster}" />
    EOD;
                            }
                        }
                        break;
                    default:
                        Debug::log('Missing support for media type: ' . $media->type);
                }
            }
        }
    return $media_html;
    }

    private static function compareTweetId($tweet1, $tweet2)
    {
        return (intval($tweet1['id']) < intval($tweet2['id']) ? 1 : -1);
    }

    //The aim of this function is to get an API key and a guest token
    //This function takes 2 requests, and therefore is cached
    private function getApiKey($forceNew = 0)
    {
        $cacheFactory = new CacheFactory();

        $r_cache = $cacheFactory->create();
        $scope = 'TwitterBridge';
        $r_cache->setScope($scope);
        $r_cache->setKey(['refresh']);
        $data = $r_cache->loadData();

        $refresh = null;
        if ($data === null) {
            $refresh = time();
            $r_cache->saveData($refresh);
        } else {
            $refresh = $data;
        }

        $cacheFactory = new CacheFactory();

        $cache = $cacheFactory->create();
        $cache->setScope($scope);
        $cache->setKey(['api_key']);
        $data = $cache->loadData();

        $apiKey = null;
        if ($forceNew || $data === null || (time() - $refresh) > self::GUEST_TOKEN_EXPIRY) {
            $twitterPage = getContents('https://twitter.com');

            $jsLink = false;
            $jsMainRegexArray = [
                '/(https:\/\/abs\.twimg\.com\/responsive-web\/web\/main\.[^\.]+\.js)/m',
                '/(https:\/\/abs\.twimg\.com\/responsive-web\/web_legacy\/main\.[^\.]+\.js)/m',
                '/(https:\/\/abs\.twimg\.com\/responsive-web\/client-web\/main\.[^\.]+\.js)/m',
                '/(https:\/\/abs\.twimg\.com\/responsive-web\/client-web-legacy\/main\.[^\.]+\.js)/m',
            ];
            foreach ($jsMainRegexArray as $jsMainRegex) {
                if (preg_match_all($jsMainRegex, $twitterPage, $jsMainMatches, PREG_SET_ORDER, 0)) {
                    $jsLink = $jsMainMatches[0][0];
                    break;
                }
            }
            if (!$jsLink) {
                returnServerError('Could not locate main.js link');
            }

            $jsContent = getContents($jsLink);
            $apiKeyRegex = '/([a-zA-Z0-9]{59}%[a-zA-Z0-9]{44})/m';
            preg_match_all($apiKeyRegex, $jsContent, $apiKeyMatches, PREG_SET_ORDER, 0);
            $apiKey = $apiKeyMatches[0][0];
            $cache->saveData($apiKey);
        } else {
            $apiKey = $data;
        }

        $cacheFac2 = new CacheFactory();

        $gt_cache = $cacheFactory->create();
        $gt_cache->setScope($scope);
        $gt_cache->setKey(['guest_token']);
        $guestTokenUses = $gt_cache->loadData();

        $guestToken = null;
        if (
            $forceNew || $guestTokenUses === null || !is_array($guestTokenUses) || count($guestTokenUses) != 2
            || $guestTokenUses[0] <= 0 || (time() - $refresh) > self::GUEST_TOKEN_EXPIRY
        ) {
            $guestToken = $this->getGuestToken($apiKey);
            if ($guestToken === null) {
                if ($guestTokenUses === null) {
                    returnServerError('Could not parse guest token');
                } else {
                    $guestToken = $guestTokenUses[1];
                }
            } else {
                $gt_cache->saveData([self::GUEST_TOKEN_USES, $guestToken]);
                $r_cache->saveData(time());
            }
        } else {
            $guestTokenUses[0] -= 1;
            $gt_cache->saveData($guestTokenUses);
            $guestToken = $guestTokenUses[1];
        }

        $this->apiKey      = $apiKey;
        $this->guestToken  = $guestToken;
        $this->authHeaders = [
            'authorization: Bearer ' . $apiKey,
            'x-guest-token: ' . $guestToken,
        ];

        return [$apiKey, $guestToken];
    }

    // Get a guest token. This is different to an API key,
    // and it seems to change more regularly than the API key.
    private function getGuestToken($apiKey)
    {
        $headers = [
            'authorization: Bearer ' . $apiKey,
        ];
        $opts = [
            CURLOPT_POST => 1,
        ];

        try {
            $pageContent = getContents('https://api.twitter.com/1.1/guest/activate.json', $headers, $opts, true);
            $guestToken = json_decode($pageContent['content'])->guest_token;
        } catch (Exception $e) {
            $guestToken = null;
        }
        return $guestToken;
    }

    /**
     * Tries to make an API call to twitter.
     * @param $api string API entry point
     * @param $params array additional URI parmaeters
     * @return object json data
     */
    private function makeApiCall($api, $params)
    {
        $uri = self::API_URI . $api . '?' . http_build_query($params);

        $retries = 1;
        $retry = 0;
        do {
            $retry = 0;

            try {
                $result = getContents($uri, $this->authHeaders, [], true);
            } catch (HttpException $e) {
                switch ($e->getCode()) {
                    case 401:
                        // fall-through
                    case 403:
                        if ($retries) {
                            $retries--;
                            $retry = 1;
                            $this->getApiKey(1);
                            continue 2;
                        }
                        // fall-through
                    default:
                        throw $e;
                }
            }
        } while ($retry);

        $data = json_decode($result['content']);

        return $data;
    }
}
