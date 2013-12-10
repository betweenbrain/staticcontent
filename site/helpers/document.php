<?php
jimport('joomla.log.log');

abstract class StaticContentHelperDocument
{
	private static $_LOG;



	public function __construct($config = array()) {
     parent::__construct($config);

     //initialize vars
     $this->option = JFactory::getApplication()->input->get('option');
     $this->_comParams = JComponentHelper::getParams($this->option);

     //cache callbacks
     $this->_cache = JCache::getInstance('callback', array(
                 'defaultgroup' => $this->option,
                 'lifetime' => $this->_comParams->get('callback_lifetime', 86400) //1 day duration
             ));
     $this->_cache->setCaching(true);

     //cache request
     $this->_cachePage = JCache::getInstance('output', array(
                 'defaultgroup' => $this->option,
                 'lifetime' => $this->_comParams->get('pages_lifetime', 86400) //1 day duration
             ));
     $this->_cachePage->setCaching(true);

     //load helpers
     $this->loadHelper('document');
     $this->loadHelper('menu');
     $this->loadHelper('url');

  $this->base_directory = JPath::clean($this->_comParams->get('base_directory'));

  if (substr($this->base_directory, -1) != '/') {
   $this->base_directory = $this->base_directory . '/';
  }

  $now                  = date('d-m-Y');
  $this->base_directory = $this->base_directory . $now . '/';
 }
	
	static public function body($page,$pageLinks,$itemLevel)
	{
		$baseFolder = ($itemLevel <= 0) ? '' : str_repeat('../',$itemLevel) ;
		
		$body = $page->content;
		$domDocument = new DOMDocument();
		$domDocument->loadHTML($body);
		
		$base = $domDocument->getElementsByTagName('base');		
		
		//replace base
		if (!empty($base)) {
			foreach ($base as $node) {
				$href = $node->getAttribute('href');
				$body = str_replace('<base href="'.$href.'" />','<base href="'.$baseFolder.'index.html" />',$body);
			}
		}
		
		$links = $domDocument->getElementsByTagName('link');
		$scripts = $domDocument->getElementsByTagName('script');
		$images = $domDocument->getElementsByTagName('img');
	
		$body = str_replace(JURI::root(),'',$body);
		
		$rootURL = JURI::root(true);
		$baseURL = JURI::base(true);
		
		foreach ($links as $link) {
			$linkHref = $link->getAttribute('href');
			if (!empty($rootURL)) {
				$cleanLinkHref = str_replace(JURI::root(true).'/', '', $linkHref);
			} else {
				$cleanLinkHref = $linkHref;
			}
			if (strpos($linkHref,'format=feed') !== false) {
				$cleanLinkHref = 'feed.xml';
			}
			$body = str_replace('href="'.htmlspecialchars($linkHref).'"','href="'.$cleanLinkHref.'"',$body);
			self::copyFile($link, 'href');
		}
		foreach ($images as $img) {
			$imgHref = $img->getAttribute('src');
			
			$cleanImgHref = str_replace(str_replace(JURI::base(true),'',JURI::base()), '', $imgHref);
			if (!empty($baseURL))
				$cleanImgHref = str_replace(JURI::base(true).'/', '', $cleanImgHref);
			$cleanImgHref = str_replace(JURI::base(true), '', $cleanImgHref);
			$img->setAttribute('src',$cleanImgHref);
			$body = str_replace('src="'.$imgHref.'"','src="'.$cleanImgHref.'"',$body);
			
			self::copyFile($img, 'src');
		}
		foreach ($scripts as $script) {
			$scriptHref = $script->getAttribute('src');
			if (!empty($rootURL)) {
				$cleanScriptHref = str_replace(JURI::root(true).'/', '', $scriptHref);
			} else {
				$cleanScriptHref = $scriptHref;
			}
			$body = str_replace('src="'.$scriptHref.'"','src="'.$cleanScriptHref.'"',$body);
			self::copyFile($script, 'src');
		}
		
		unset($domDocument);
		
		if (!empty($pageLinks['print'])) {
			$body = self::fixPrintLinks($body,$pageLinks['print']);
		}
		$body = self::fixMenuLinks($body,$pageLinks);
		$body = self::fixBannersLinks($body);
		
		return $body;
	}
	
	static public function fixMenuLinks($body,$menuItems)
	{
		foreach ($menuItems['menu'] as $menuItem)
		{	
			$sefLink = $menuItem->file;
			$originalLink = JURI::root(true).'/'.$menuItem->relative;
			$body = str_replace('href="'.$originalLink.'"','href="'.$sefLink.'"',$body);
		}
		
		foreach ($menuItems['pages'] as $menuItem)
		{	
			$sefLink = $menuItem->file;
			$originalLink = JURI::root(true).'/'.$menuItem->relative;
			$body = str_replace('href="'.$originalLink.'"','href="'.$sefLink.'"',$body);
		}
		
		return $body;
	}
	
	static public function fixPrintLinks($body,$printLinks)
	{
		foreach ($printLinks as $originaPrintLink => $sefPrintLink) {
			$originaPrintLink = str_replace(JURI::base(true).'/','', $originaPrintLink);
			$body = str_replace('href="'.$originaPrintLink.'"','href="'.$sefPrintLink.'"',$body);
		}
		
		return $body;
	}
	
	static public function fixBannersLinks($body)
	{
		$db = JFactory::getDbo();
		$db->setQuery('SELECT id,clickurl FROM #__banners');
		$banners = $db->loadObjectList();
		
		foreach ($banners as $banner)
		{
			$bannerNoSEFUrl = JURI::root(true).'/index.php/component/banners/click/'.$banner->id;
			$bannerSEFUrl = $banner->clickurl;
			$body = str_replace('href="'.$bannerNoSEFUrl.'"','href="'.$bannerSEFUrl.'"',$body);
		}
		
		return $body;
	}
	
	static public function copyFile($node,$attribute)
	{
		$option = JFactory::getApplication()->input->get('option');
		$comParams = JComponentHelper::getParams($option);
		$path_source = JPath::clean($comParams->get('base_directory'));

		if (substr($path_source, -1) != '/') {
			$path_source = $path_source . '/';
		}

		$now                  = date('d-m-Y');
		$path_source = $path_source . $now;

		if ($node->hasAttribute($attribute)) {
			$url = $node->getAttribute($attribute);
			$uri = JFactory::getURI($url);
			$interno = false;

			$uriHost = $uri->getHost();
			if ( (!empty($uriHost) && $uriHost == JURI::getInstance()->getHost()) || strpos($url,JURI::base(true))) $interno = true;
			
			if(JURI::isInternal($url) == $interno || strpos($url,'index.php') !== false) return;

			$path = $uri->getPath();

			if (strpos($path,'~') > 0) {
				$path = explode('/',$path);
				array_shift($path);
				array_shift($path);
				$path = implode($path,'/');
			}
			$uri->setPath($path);
			$tmp = str_replace('/',DIRECTORY_SEPARATOR,$path);

			$intersect = array_intersect(explode(DIRECTORY_SEPARATOR,JPATH_ROOT), explode(DIRECTORY_SEPARATOR,$tmp));
			$intersect = array_filter($intersect);
			$tmpBasePath = implode('/',$intersect);
			if (!empty($tmpBasePath)) $tmpBasePath .= '/';
			
			if (!empty($tmpBasePath)) {
				$path = str_replace($tmpBasePath,'',$path);
			}
			else {
				$path = $uri->getPath();
			}
			
			if (empty($path) || $path == '<') return;
			
			$sourceFilePath = JPath::clean(JPATH_ROOT.DIRECTORY_SEPARATOR.$path);
			$filePath = JPath::clean($path_source.DIRECTORY_SEPARATOR.$path);

			if (JFile::exists($sourceFilePath)) {
				//creating folders
				JFolder::create(dirname($filePath));
				//copy file
				if (JFile::copy($sourceFilePath, $filePath)) {
					//copy all url(*) data
					if (strtolower( JFile::getExt($sourceFilePath) ) == 'css') {
						$css_file_content = JFile::read($sourceFilePath);
						preg_match_all('/(url|URL)\(.*?\)/i', $css_file_content, $data_array);
						if (!empty($data_array[0])) {
							$baseSourceFilePath = dirname($sourceFilePath).DIRECTORY_SEPARATOR;
							$baseFilePath = dirname($filePath).DIRECTORY_SEPARATOR;
							
							foreach($data_array[0] as $img) {
								$img = trim($img);
								$removeDirs = substr_count($img,'./');
								$removeDirs += substr_count($img,'../');
								$clean_path = str_replace('../','',$img);
								$clean_path = str_replace('"','',$clean_path);
								$clean_path = str_replace("'",'',$clean_path);
								$clean_path = str_replace('(','',$clean_path);
								$clean_path = str_replace(')','',$clean_path);
								$clean_path = str_replace('url','',$clean_path);
								$clean_path = str_replace('URL','',$clean_path);
								
								for ($d=1;$d<=$removeDirs;$d++) {
									$sourceFilePath = dirname($baseSourceFilePath).DIRECTORY_SEPARATOR;
									$filePath = dirname($baseFilePath).DIRECTORY_SEPARATOR;
								}
								$sourceFilePath = $sourceFilePath.$clean_path;
								$filePath = $filePath.$clean_path;
								$sourceFilePath = JPath::clean($sourceFilePath);
								$filePath = JPath::clean($filePath);
								
								if (JFile::exists($sourceFilePath)) {
									//creating folders
									JFolder::create(dirname($filePath));
									if (!JFile::copy($sourceFilePath, $filePath)) {
										die(JText::sprintf('COM_STATICCONTENT_MSG_FAILURE_COPY_FILE',$sourceFilePath));
									}
								} else {
									self::log("Cant copy file {$sourceFilePath}");
								}
							}
						}
					}
				}
				else {
					die(JText::sprintf('COM_STATICCONTENT_MSG_FAILURE_COPY_FILE',$sourceFilePath));
				}
			} else {
				self::log("Cant find file {$sourceFilePath}");
			}
		}
	}
	
	static private function log($message)
	{
		if (empty(self::$_LOG[$message]))
			self::$_LOG[$message] = true;
	}
	
	public static function writeLog()
	{
		$date = JFactory::getDate()->format('Y-m-d');

		// Add the logger.
		JLog::addLogger(
		    array(
		        'text_file' => 'com_statiscontent.'.$date.'.php'
		    )
		);
		
		foreach (self::$_LOG as $message => $trunk)
			JLog::add($message);
	}
}
