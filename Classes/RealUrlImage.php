<?php

/**
 * Main class
 *
 * @author Tim Lochmüller
 */

namespace FRUIT\FlRealurlImage;

use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\PathUtility;
use TYPO3\CMS\Extbase\Object\ObjectManager;
use TYPO3\CMS\Extbase\Utility\ArrayUtility;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;

/**
 * The main class of fl_realurl_image
 */
class RealUrlImage extends ContentObjectRenderer {

	/**
	 * IMAGE-Object config
	 *
	 * @var array
	 */
	protected $IMAGE_conf = array();

	/**
	 * @var \TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer
	 */
	protected $currentCobj = NULL;

	/**
	 * config.fl_realurl_image from setup.txt / TypoScript merged with IMAGE-Object.fl_realurl_image
	 *
	 * @var array
	 */
	protected $fl_conf = array();

	/**
	 * image Array of Typo3
	 *
	 * @var array
	 */
	protected $image = array();

	/**
	 * info about the file type
	 *
	 * @var array
	 */
	protected $fileTypeInformation = array();

	/*
	  - 0: Height
	  - 1: Weight
	  - 2: Type-Ending
	  - 3: File-Name generated by Typo3 (typo3temp/pics/2355fb8381.jpg (after changing, cropping, ...)
	  - origFile: fileadmin/series/advanced-a-series/18__Urdhva_Kukkutasana_A.JPG (before changing, cropping, ...)
	  - origFile_mtime: 1249081200
	  - fileCacheHash: ed0180473f
	 */
	protected $new_fileName = '';

	protected $org_fileName = '';

	protected $enable = TRUE;

	/**
	 * @var Configuration
	 */
	protected $configuration = NULL;

	/**
	 * Build up the object
	 */
	public function __construct() {
		$objectManager = new ObjectManager();
		$this->configuration = $objectManager->get('FRUIT\\FlRealurlImage\\Configuration');
	}

	/**
	 * Outputting the image that fits to the realurl_image request
	 * Notice: normally the image should be in the static file cache
	 * ... so this is an emergency action only when no image is in static file cache
	 * -> to do
	 * 1) outputting image
	 * 2) recreate the static file cache
	 * 3) updating the DB-Entry
	 *
	 * @return void
	 */
	public function showImage() {
		// Path of the requested image
		$path = str_replace(GeneralUtility::getIndpEnv('TYPO3_SITE_URL'), '', GeneralUtility::getIndpEnv('TYPO3_REQUEST_URL'));
		$path = trim($path, '/');
		$cacheIdentifier = $path;
		// look up in DB-table if there is a image stored for this realurl
		$cache = $this->getCache();
		if ($cache->has($cacheIdentifier)) {
			// get the information to the requested image
			$data = unserialize($cache->get($cacheIdentifier));
			// update DB to idicate that image was requested
			if (!strstr($data['page_id'], '?')) {
				$page_id = trim($data['page_id'] . ',?', ',');
			} else {
				$page_id = trim($data['page_id'], ',');
			}
			$data['tstamp'] = time();
			$data['page_id'] = $page_id;

			$cache->set($cacheIdentifier, serialize($data));

			// linkStatic is switched on, then relink the image static.
			// The obviously lost image will be shown much faster next time
			if ($this->configuration->get('fileLinks')) {
				$this->createFileCache($data['image_path'], $data['realurl_path']);
			}
			// cacheControl is switched on and the image has not been modified since last request
			// => loaded from browser cache 
			if ($this->configuration->get('cacheControl') && $_SERVER['HTTP_IF_MODIFIED_SINCE']) {
				$lastGet = strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']);
				if ($data['tstamp'] != 0 && $lastGet <= $data['tstamp']) {
					header('HTTP/1.1 304 Not Modified');
					die();
				}
			} // send headers for image and output the image
			// this is the "manual" way to display an image
			else {
				$info = getimagesize(PATH_site . $data['image_path']);
				header('Content-Type: ' . $info['mime']);
				header('Content-Length: ' . filesize(PATH_site . $data['image_path']));
				if ($this->configuration->get('cacheControl') && $data['tstamp'] != 0) {
					header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $data['tstamp']) . ' GMT');
				}
				readfile(PATH_site . $data['image_path']);
				die();
			}
		}
		// no image available => die empty - continue page processing
	}

	/**
	 * Add the absrefprefix
	 *
	 * @param $url
	 *
	 * @return string
	 */
	public function addAbsRefPrefix($url) {
		return htmlspecialchars($GLOBALS['TSFE']->absRefPrefix) . ltrim($url, '/');
	}

	/**
	 * main function of tx_flrealurlimage class
	 *
	 * @param array $conf IMAGE-Object configuration array
	 * @param array $info image info array:
	 * @param mixed $file
	 *
	 * @param null  $cObj
	 *
	 * @return string
	 */
	public function main($conf, $info, $file = NULL, $cObj = NULL) {
		$this->init($conf, $info, $file, $cObj);

		if ($this->enable && trim($this->org_fileName) != '') {
			$new = $this->generateFileName();
			if ($new !== '') {
				return $new;
			}
		}
		return $this->org_fileName;

	}

	/**
	 * initializing tx_flrealurlimage class
	 *
	 * @param array $conf  IMAGE-Object configuration array
	 * @param array $image image info array:
	 * @param mixed $file
	 *
	 * @param       $cObj
	 */
	protected function init($conf, $image, $file, $cObj) {
		// IMAGE_conf
		$this->IMAGE_conf = $conf;
		$this->currentCobj = $cObj;

		// fl_conf
		$global_conf = array();
		if (is_array($GLOBALS['TSFE']->tmpl->setup['config.']['fl_realurl_image.'])) {
			$global_conf = $GLOBALS['TSFE']->tmpl->setup['config.']['fl_realurl_image.'];
		}
		$local_conf = array();
		if (is_array($conf['fl_realurl_image.'])) {
			$local_conf = $conf['fl_realurl_image.'];
		}

		$global_conf = ArrayUtility::arrayMergeRecursiveOverrule($global_conf, $local_conf);

		$this->fl_conf = $global_conf;

		// image Array
		$this->image = $image;

		// filetype
		$this->fileTypeInformation = $file;

		// new_fileName
		if ($conf['fl_realurl_image']) {
			$this->new_fileName = $conf['fl_realurl_image'];
		} else {
			$this->new_fileName = $GLOBALS['TSFE']->tmpl->setup['config.']['fl_realurl_image'];
		}
		if ($this->new_fileName == '1') {
			$this->new_fileName = '';
		}

		$enableByConfiguration = (bool)$this->fl_conf['enable'];
		if (!$enableByConfiguration) {
			$this->enable = $enableByConfiguration;
		}

		// enable
		if (strtolower($this->new_fileName) == 'off' || $this->new_fileName === 0 || // fl_realurl_image switched off on this page
			!is_array($GLOBALS['TSFE']->tmpl->setup['config.']['fl_realurl_image.']) // no static template
		) {
			$this->enable = FALSE;
		}

		// org_fileName
		$this->org_fileName = $image[3];
	}

	/**
	 * The main function of fl_realurl_image
	 * - generates $this->new_fileName
	 * - writes in DB
	 * - creats static file caches
	 *
	 * @param       nothing
	 *
	 * @return        string       the new file name
	 */
	protected function generateFileName() {
		// generate a text basis for a speaking file name
		if ($this->fl_conf['data'] && $this->new_fileName == '') {
			$this->new_fileName = $this->generateTextBase();
		}
		if ($this->new_fileName === '') {
			return $this->new_fileName;
		}
		unset($this->fl_conf['data']); // important otherwise stdWrap overwrites so far generated new_fileName
		// if $textBase is already a filename then get only the name itself with no path or ending
		if (strstr($this->new_fileName, '/')) {
			$this->new_fileName = basename($this->new_fileName);
		}
		if (strstr($this->new_fileName, '.')) {
			$this->new_fileName = str_replace(array(
				'.jpg',
				'.JPG',
				'.jpeg',
				'.JPEG',
				'.png',
				'.PNG',
				'.gif',
				'.GIF'
			), '', $this->new_fileName);
		}
		// make this text basis suitable for a file name
		$this->new_fileName = $this->smartEncoding($this->new_fileName);
		// add the folder
		$this->new_fileName = $this->fl_conf['folder'] . '/' . $this->new_fileName;
		// add hash and ending = find a not occupied file name
		$this->new_fileName = $this->addHash($this->new_fileName);
		$this->new_fileName = $this->writeDBcollisionHandling($this->new_fileName);
		// delete the old file cache if new image is different to old
		$this->deleteFileCache($this->org_fileName, $this->new_fileName);
		// create the new file cache
		$this->createFileCache($this->org_fileName, $this->new_fileName);
		return $this->virtualPathRemove($this->new_fileName);
	}

	/**
	 * generates a text Base for generation of a speaking file name
	 *
	 * @param       nothing
	 *
	 * @return        string       Text name base
	 */
	protected function generateTextBase() {
		// get info to image
		$pageInfo = $this->getPAGEinfo();
		$falInfo = $this->getFALInfo();
		$falReferenceInfo = $this->getFALReferenceInfo();

		// walk the options until a possible base for a file-name is found
		$parts = GeneralUtility::trimExplode('//', $this->fl_conf['data'], TRUE);
		$partSize = sizeof($parts);
		for ($i = 0; $i < $partSize; $i++) {
			list($source, $item) = GeneralUtility::trimExplode(':', $parts[$i], TRUE);

			switch ($source) {
				case 'falref':
					if ($falReferenceInfo[$item] && strlen(trim($falReferenceInfo[$item]))) {
						return trim($falReferenceInfo[$item]);
					}
					break;
				case 'fal':
					if ($falInfo[$item] && strlen(trim($falInfo[$item]))) {
						return trim($falInfo[$item]);
					}
					break;
				case 'ts':
					if ($this->IMAGE_conf[$item] || $this->IMAGE_conf[$item . '.']) {
						$tsResult = $this->currentCobj->stdWrap($this->IMAGE_conf[$item], $this->IMAGE_conf[$item . '.']);
						if (strlen(trim($tsResult))) {
							return trim($tsResult);
						}
					}
					break;
				case 'file':
					if ($this->image[$item] && strlen(trim($this->image[$item]))) {
						return trim($this->image[$item]);
					}
					break;
				case 'page':
					if ($pageInfo[$item] && strlen(trim($pageInfo[$item]))) {
						return trim($pageInfo[$item]);
					}
					break;
				default:
					if ($parts[$i] && strlen(trim($parts[$i]))) {
						return trim($parts[$i]);
					}
					break;
			}
		}
		return '';
	}

	/**
	 * @return \FRUIT\FlRealurlImage\Service\FileInformation
	 */
	protected function getFileInformation() {
		return GeneralUtility::makeInstance('FRUIT\\FlRealurlImage\\Service\\FileInformation');
	}

	/**
	 * @return array
	 */
	protected function getFALReferenceInfo() {
		if ($fileInformation = $this->getFileInformation()) {
			return $fileInformation->getByFalReference($this->image, $this->fileTypeInformation, $this->IMAGE_conf, $this->currentCobj);
		}
		return array();
	}

	/**
	 * @return array
	 */
	protected function getFALInfo() {
		if ($fileInformation = $this->getFileInformation()) {
			return $fileInformation->getByFal($this->image);
		}
		return array();
	}

	/**
	 * get (meta) info for the current Page
	 *
	 * @param       nothing
	 *
	 * @return        array        (meta) info from page
	 */
	protected function getPAGEinfo() {
		$rootLineDepth = sizeof($GLOBALS['TSFE']->tmpl->rootLine);
		$pageInfo = $GLOBALS['TSFE']->tmpl->rootLine[$rootLineDepth - 1];
		return $pageInfo;
	}

	/**
	 * Convert a a text to something that can be used as a file name:
	 * - Convert spaces to underscores
	 * - Convert non A-Z characters to ASCII equivalents
	 * - Convert some special things like the 'ae'-character
	 * - Strip off all other symbols
	 * - pass through rawurlencode()
	 * Works with the character set defined as "forceCharset"
	 *
	 * @param       string $textBase a text string to encode into a nice file name
	 *
	 * @return      string      Encoded text string
	 * @see rootLineToPath()
	 */
	protected function smartEncoding($textBase) {
		// decode $textBase
		$textBase = urldecode($textBase);

		// stdWrap
		$textBase = $this->stdWrap($textBase, $this->fl_conf);
		// Convert some special tokens to the space character:
		$space = '-';
		if ($this->fl_conf['spaceCharacter']) {
			$space = $this->fl_conf['spaceCharacter'];
		}
		// spaceCharacter
		$textBase = strtr($textBase, ' -+_', $space . $space . $space);
		// smartEncoding
		if ($this->fl_conf['smartEncoding']) {
			$charset = $GLOBALS['TYPO3_CONF_VARS']['BE']['forceCharset'] ? $GLOBALS['TYPO3_CONF_VARS']['BE']['forceCharset'] : $GLOBALS['TSFE']->defaultCharSet;
			$textBase = $GLOBALS['TSFE']->csConvObj->specCharsToASCII($charset, $textBase); // Convert extended letters to ascii equivalents
			$textBase = preg_replace('/[^a-z0-9\/\\\]/i', $space, $textBase); // replace the rest with $space
		}
		// spaceCharacter
		$textBase = preg_replace('/[\/\\' . $space . ']+' . '/i', $space, $textBase); // Convert multiple $space to a single one
		$textBase = trim($textBase, $space); // trim $space
		// encoded $textBase
		$textBase = rawurlencode($textBase);
		// return
		return $textBase;
	}

	/**
	 * add a very simple Hash to $textBase
	 *
	 * @param        string $textBase Text base: e.g. typo3temp/fl_realurl_image/myimage-name
	 *
	 * @return        string        Text base: e.g. typo3temp/fl_realurl_image/myimage-name-a7r
	 */
	protected function addHash($textBase) {
		$org_base = PathUtility::pathinfo($this->org_fileName, PATHINFO_BASENAME);
		$org_end = PathUtility::pathinfo($this->org_fileName, PATHINFO_EXTENSION);

		$hashBase = $org_base;
		if (isset($this->image[3]) && strlen($this->image[3])) {
			$hashBase = GeneralUtility::shortMD5($this->image[3], 24);
		}
		$hashLength = isset($this->fl_conf['hashLength']) ? (int)$this->fl_conf['hashLength'] : 0;

		if ($hashLength) {
			$space = $this->getSpaceCharacter();
			if ($hashLength > strlen($hashBase)) {
				$hashLength = strlen($hashBase);
			}
			$textBase .= $space . substr($org_base, 0, $hashLength);
		}
		return $textBase . '.' . $org_end;
	}

	/**
	 * Return the Space character
	 *
	 * @return string
	 */
	protected function getSpaceCharacter() {
		if (isset($this->fl_conf['spaceCharacter']) && strlen($this->fl_conf['spaceCharacter'])) {
			return $this->fl_conf['spaceCharacter'];
		}
		return '-';
	}

	/**
	 * Writes $textBase in the fl_realurl_image_cache table
	 * arter collissions handling
	 *
	 * @param string $textBase the path of the new image
	 *
	 * @return string|NULL the path of the new image after collision handling
	 */
	protected function writeDBcollisionHandling($textBase) {
		list($trunk, $ending) = explode('.', $textBase);
		$count = '';
		$cache = $this->getCache();
		while (1) {
			if ($this->fl_conf['hashLength'] && $count && $count > 0) {
				$space = '-';
				if ($this->fl_conf['spaceCharacter']) {
					$space = $this->fl_conf['spaceCharacter'];
				}
			} else {
				$space = '';
			}
			$newImageName_probe = $trunk . $space . $count . '.' . $ending;

			if (!$cache->has($newImageName_probe)) {
				$this->writeDB($newImageName_probe);
				return $newImageName_probe;
			} else {
				// go count one up to avoid collision
				if ($count === '') { // Set count to 0 when run once
					$count = 0;
				} else {
					$count++;
				}
			}
		}
		return NULL;
	}

	/**
	 * Writes in the DB - if not taken
	 *
	 * @param        string $new_fileName the path to write in the DB
	 *
	 * @return        boolean        successfull?
	 */
	protected function writeDB($new_fileName) {

		$cache = $this->getCache();
		$cacheIdent = $this->org_fileName;
		if ($cache->has($cacheIdent)) {
			$data = unserialize($cache->get($new_fileName));
			$pids = GeneralUtility::intExplode(',', $data['page_id'], TRUE);
			if (!in_array((int)$GLOBALS['TSFE']->id, $pids)) {
				$pids[] = (int)$GLOBALS['TSFE']->id;
			}
			$data['tstamp'] = time();
			$data['page_id'] = implode(',', $pids);
		} else {
			$data = array(
				'crdate'     => time(),
				'tstamp'     => time(),
				'image_path' => $this->org_fileName,
				'new_path'   => $new_fileName,
				'page_id'    => $GLOBALS['TSFE']->id
			);
		}
		$cache->set($cacheIdent, serialize($data));
		return TRUE;
	}

	/**
	 * Deleting the old image in the fl_realurl_image file cache
	 * if it is different from the original image.
	 * A new, different image has to take this place later and will carie it's name
	 *
	 * @param        string $org_path the path to the original image e.g.: typo3temp/pics/2305e38d9c.jpg
	 * @param        string $new_path the path to the new image
	 *
	 * @return      NULL
	 */
	protected function deleteFileCache($org_path, $new_path) {
		if (TYPO3_OS == 'WIN') {
			if (is_file($new_path) && (md5_file($org_path) != md5_file($new_path))) {
				unlink($new_path);
			}
		} else {
			if (is_file(PATH_site . $new_path) && (md5_file(PATH_site . $org_path) != md5_file(PATH_site . $new_path))) {
				unlink(PATH_site . $new_path);
			}
		}
		return;
	}

	/**
	 * creates a hard-link / sym-link / copy of the oritinal image to the new location
	 *
	 * @param string $relativeOriginalPath the path to the original image e.g.: typo3temp/pics/2305e38d9c.jpg
	 * @param string $relativeNewPath      the path to the new image
	 *
	 * @throws \Exception
	 */
	protected function createFileCache($relativeOriginalPath, $relativeNewPath) {
		$absoluteOriginalPath = GeneralUtility::getFileAbsFileName($relativeOriginalPath);

		if (!is_file($absoluteOriginalPath)) {
			$relativeOriginalPath = rawurldecode($relativeOriginalPath);
			$absoluteOriginalPath = GeneralUtility::getFileAbsFileName($relativeOriginalPath);
			// error no valid $relativeOriginalPath
			if (!is_file($absoluteOriginalPath)) {
				return;
			}
		}

		if ($this->configuration->get('fileLinks') == 'none') {
			return;
		}

		$absoluteNewPath = GeneralUtility::getFileAbsFileName($relativeNewPath);
		if (is_file($absoluteNewPath) || is_link($absoluteNewPath)) {
			return;
		}

		// Better to throw a exception to find the mistake?!?!
		if (empty($relativeOriginalPath)) {
			return;
		}

		// create folder if required
		$new_folder = GeneralUtility::dirname($relativeNewPath);
		if ($new_folder && !is_dir($new_folder)) {
			if (!GeneralUtility::mkdir($new_folder)) {
				throw new \Exception('Can\'t create the fl_realurl_image Folder "' . $new_folder . '"');
			}
		}
		if (TYPO3_OS == 'WIN') {
			if ($this->configuration->get('fileLinks') == 'copy') {
				copy($relativeOriginalPath, $absoluteNewPath);
			} else {
				// symlink is not possible
				exec('fsutil hardlink create "' . $relativeNewPath . '" "' . $relativeOriginalPath . '"');
			}
		} else {
			if ($this->configuration->get('fileLinks') == 'copy') {
				copy($absoluteOriginalPath, $absoluteNewPath);
			} elseif ($this->configuration->get('fileLinks') == 'symLink') {
				symlink($absoluteOriginalPath, $absoluteNewPath);
			} else {
				link($relativeOriginalPath, $absoluteNewPath);
			}
		}
	}

	/**
	 * Removing a part from the path
	 *
	 * @param        string $path the path
	 *
	 * @return        string      the path after removing
	 */
	protected function virtualPathRemove($path) {
		if ($this->configuration->get('virtualPathRemove')) {
			return str_replace($this->configuration->get('virtualPathRemove'), '', $path);
		}
		return $path;
	}

	/**
	 * Get the static file cache
	 *
	 * @return \TYPO3\CMS\Core\Cache\Frontend\FrontendInterface
	 * @throws \TYPO3\CMS\Core\Cache\Exception\NoSuchCacheException
	 */
	protected function getCache() {
		static $cache = NULL;
		if ($cache !== NULL) {
			return $cache;
		}
		/** @var \TYPO3\CMS\Core\Cache\CacheManager $cacheManager */
		$objectManager = new ObjectManager();
		$cacheManager = $objectManager->get('TYPO3\\CMS\\Core\\Cache\\CacheManager');
		$cache = $cacheManager->getCache('fl_realurl_image');
		return $cache;
	}

	/**
	 * @param $textBase
	 * @param $fl_conf
	 *
	 * @return string
	 */
	public function stdWrapDummy($textBase, $fl_conf) {
		$objectContentRender = new ContentObjectRenderer();
		return $objectContentRender->stdWrap($textBase, $this->fl_conf);
	}

}