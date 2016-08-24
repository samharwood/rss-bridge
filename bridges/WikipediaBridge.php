<?php

define('WIKIPEDIA_SUBJECT_TFA', 0); // Today's featured article
define('WIKIPEDIA_SUBJECT_DYK', 1); // Did you know...

class WikipediaBridge extends BridgeAbstract{
	public function loadMetadatas(){
		$this->maintainer = 'logmanoriginal';
		$this->name = 'Wikipedia bridge for many languages';
		$this->uri = 'https://www.wikipedia.org/';
		$this->description = 'Returns articles for a language of your choice';

        $this->parameters[] = array(
          'language'=>array(
            'name'=>'Language',
            'type'=>'list',
            'required'=>true,
            'title'=>'Select your language',
            'exampleValue'=>'English',
            'values'=>array(
              'English'=>'en',
              'German'=>'de',
              'French'=>'fr',
              'Esperanto'=>'es'
            )
          ),
          'subject'=>array(
            'name'=>'Subject',
            'type'=>'list',
            'required'=>true,
            'title'=>'What subject are you interested in?',
            'exampleValue'=>'Today\'s featured article',
            'values'=>array(
              'Today\'s featured article'=>'tfa',
              'Did you know…'=>'dyk'
            )
          ),
          'fullarticle'=>array(
            'name'=>'Load full article',
            'type'=>'checkbox',
            'title'=>'Activate to always load the full article'
          )
        );
	}

	public function collectData(){
        $params=$this->parameters[$this->queriedContext];
		if(!isset($params['language']['value']))
			$this->returnClientError('You must specify a valid language via \'&language=\'!');

		if(!$this->CheckLanguageCode(strtolower($params['language']['value'])))
			$this->returnClientError('The language code you provided (\'' . $params['language']['value'] . '\') is not supported!');

		if(!isset($params['subject']['value']))
			$this->returnClientError('You must specify a valid subject via \'&subject=\'!');

		$subject = WIKIPEDIA_SUBJECT_TFA;
		switch($params['subject']['value']){
			case 'tfa':
				$subject = WIKIPEDIA_SUBJECT_TFA;
				break;
			case 'dyk':
				$subject = WIKIPEDIA_SUBJECT_DYK;
				break;
			default:
				$subject = WIKIPEDIA_SUBJECT_TFA;
				break;
		}

		$fullArticle = false;
		if(isset($params['fullarticle']['value']))
			$fullArticle = $params['fullarticle']['value'] === 'on' ? true : false;

		// We store the correct URI as URI of this bridge (so it can be used later!)
		$this->uri = 'https://' . strtolower($params['language']['value']) . '.wikipedia.org';

		// While we at it let's also update the name for the feed
		switch($subject){
			case WIKIPEDIA_SUBJECT_TFA:
				$this->name = 'Today\'s featured article from ' . strtolower($params['language']['value']) . '.wikipedia.org';
				break;
			case WIKIPEDIA_SUBJECT_DYK:
				$this->name = 'Did you know? - articles from ' . strtolower($params['language']['value']) . '.wikipedia.org';
				break;
			default:
				$this->name = 'Articles from ' . strtolower($params['language']['value']) . '.wikipedia.org';
				break;
		}

		// This will automatically send us to the correct main page in any language (try it!)
		$html = $this->getSimpleHTMLDOM($this->uri . '/wiki');

		if(!$html)
			$this->returnServerError('Could not load site: ' . $this->uri . '!');

		/*
		* Now read content depending on the language (make sure to create one function per language!)
		* We build the function name automatically, just make sure you create a private function ending
		* with your desired language code, where the language code is upper case! (en -> GetContentsEN).
		*/
		$function = 'GetContents' . strtoupper($params['language']['value']);

		if(!method_exists($this, $function))
			$this->returnServerError('A function to get the contents for your langauage is missing (\'' . $function . '\')!');

		/*
		* The method takes care of creating all items.
		*/
		$this->$function($html, $subject, $fullArticle);
	}

	/**
	* Returns true if the language code is part of the parameters list
	*/
	private function CheckLanguageCode($languageCode){
		$languages = $this->parameters[0]['language']['values'];

		$language_names = array();

		foreach($languages as $name=>$value)
			$language_names[] = $value;

		return in_array($languageCode, $language_names);
	}

	/**
	* Replaces all relative URIs with absolute ones
	* @param $element A simplehtmldom element
	* @return The $element->innertext with all URIs replaced
	*/
	private function ReplaceURIInHTMLElement($element){
		return str_replace('href="/', 'href="' . $this->uri . '/', $element->innertext);
	}

	/*
	* Adds a new item to $items using a generic operation (should work for most (all?) wikis)
	*/
	private function AddTodaysFeaturedArticleGeneric($element, $fullArticle){
		// Clean the bottom of the featured article
		$element->find('div', -1)->outertext = '';

		// The title and URI of the article is best defined in an anchor containint the string '...' ('full article ...')
		$target = $element->find('p/a', 0); // We'll use the first anchor as fallback
		foreach($element->find('//a') as $anchor){
			if(strpos($anchor->innertext, '...') !== false){
				$target = $anchor;
				break;
			}
		}

		$item = array();
		$item['uri'] = $this->uri . $target->href;
		$item['title'] = $target->title;

		if(!$fullArticle)
			$item['content'] = strip_tags($this->ReplaceURIInHTMLElement($element), '<a><p><br><img>');
		else
			$item['content'] = $this->LoadFullArticle($item['uri']);

		$this->items[] = $item;
	}

	/*
	* Adds a new item to $items using a generic operation (should work for most (all?) wikis)
	*/
	private function AddDidYouKnowGeneric($element, $fullArticle){
		foreach($element->find('ul', 0)->find('li') as $entry){
			$item = array();

			// We can only use the first anchor, there is no way of finding the 'correct' one if there are multiple
			$item['uri'] = $this->uri . $entry->find('a', 0)->href;
			$item['title'] = strip_tags($entry->innertext);

			if(!$fullArticle)
				$item['content'] = $this->ReplaceURIInHTMLElement($entry);
			else
				$item['content'] = $this->LoadFullArticle($item['uri']);

			$this->items[] = $item;
		}
	}

	/**
	* Loads the full article from a given URI
	*/
	private function LoadFullArticle($uri){
		$content_html = $this->getSimpleHTMLDOM($uri);

		if(!$content_html)
			$this->returnServerError('Could not load site: ' . $uri . '!');

		$content = $content_html->find('#mw-content-text', 0);

		if(!$content)
			$this->returnServerError('Could not find content in page: ' . $uri . '!');

		// Let's remove a couple of things from the article
		$table = $content->find('#toc', 0); // Table of contents
		if(!$table === false)
			$table->outertext = '';

		foreach($content->find('ol.references') as $reference) // References
			$reference->outertext = '';

		return str_replace('href="/', 'href="' . $this->uri . '/', $content->innertext);
	}

	/**
	* Implementation for de.wikipedia.org
	*/
	private function GetContentsDE($html, $subject, $fullArticle){
		switch($subject){
			case WIKIPEDIA_SUBJECT_TFA:
				$element = $html->find('div[id=mf-tfa]', 0);
				$this->AddTodaysFeaturedArticleGeneric($element, $fullArticle);
				break;
			case WIKIPEDIA_SUBJECT_DYK:
				$element = $html->find('div[id=mf-dyk]', 0);
				$this->AddDidYouKnowGeneric($element, $fullArticle);
				break;
			default:
				break;
		}
	}

	/**
	* Implementation for fr.wikipedia.org
	*/
	private function GetContentsFR($html, $subject, $fullArticle){
		switch($subject){
			case WIKIPEDIA_SUBJECT_TFA:
				$element = $html->find('div[id=accueil-lumieresur]', 0);
				$this->AddTodaysFeaturedArticleGeneric($element, $fullArticle);
				break;
			case WIKIPEDIA_SUBJECT_DYK:
				$element = $html->find('div[id=SaviezVous]', 0);
				$this->AddDidYouKnowGeneric($element, $fullArticle);
				break;
			default:
				break;
		}
	}

	/**
	* Implementation for en.wikipedia.org
	*/
	private function GetContentsEN($html, $subject, $fullArticle){
		switch($subject){
			case WIKIPEDIA_SUBJECT_TFA:
				$element = $html->find('div[id=mp-tfa]', 0);
				$this->AddTodaysFeaturedArticleGeneric($element, $fullArticle);
				break;
			case WIKIPEDIA_SUBJECT_DYK:
				$element = $html->find('div[id=mp-dyk]', 0);
				$this->AddDidYouKnowGeneric($element, $fullArticle);
				break;
			default:
				break;
		}
	}

	/**
	* Implementation for eo.wikipedia.org
	*/
	private function GetContentsEO($html, $subject, $fullArticle){
		switch($subject){
			case WIKIPEDIA_SUBJECT_TFA:
				$element = $html->find('div[id=mf-artikolo-de-la-semajno]', 0);
				$this->AddTodaysFeaturedArticleGeneric($element, $fullArticle);
				break;
			case WIKIPEDIA_SUBJECT_DYK:
				$element = $html->find('div[id=mw-content-text]', 0)->find('table', 4)->find('td', 4);
				$this->AddDidYouKnowGeneric($element, $fullArticle);
				break;
			default:
				break;
		}
	}
}
