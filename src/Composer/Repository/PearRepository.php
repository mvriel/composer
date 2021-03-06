<?php

/*
 * This file is part of Composer.
 *
 * (c) Nils Adermann <naderman@naderman.de>
 *     Jordi Boggiano <j.boggiano@seld.be>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Composer\Repository;

use Composer\IO\IOInterface;
use Composer\Package\Loader\ArrayLoader;
use Composer\Util\RemoteFilesystem;
use Composer\Json\JsonFile;
use Composer\Downloader\TransportException;

/**
 * @author Benjamin Eberlei <kontakt@beberlei.de>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class PearRepository extends ArrayRepository
{
    private static $channelNames = array();

    private $url;
    private $channel;
    private $io;
    private $rfs;

    public function __construct(array $config, IOInterface $io, RemoteFilesystem $rfs = null)
    {
        if (!preg_match('{^https?://}', $config['url'])) {
            $config['url'] = 'http://'.$config['url'];
        }

        if (function_exists('filter_var') && !filter_var($config['url'], FILTER_VALIDATE_URL)) {
            throw new \UnexpectedValueException('Invalid url given for PEAR repository: '.$config['url']);
        }

        $this->url = rtrim($config['url'], '/');
        $this->channel = !empty($config['channel']) ? $config['channel'] : null;
        $this->io = $io;
        $this->rfs = $rfs ?: new RemoteFilesystem($this->io);
    }

    protected function initialize()
    {
        parent::initialize();

        $this->io->write('Initializing PEAR repository '.$this->url);
        $this->initializeChannel();
        $this->io->write('Packages names will be prefixed with: pear-'.$this->channel.'/');

        // try to load as a composer repo
        try {
            $json     = new JsonFile($this->url.'/packages.json', new RemoteFilesystem($this->io));
            $packages = $json->read();

            if ($this->io->isVerbose()) {
                $this->io->write('Repository is Composer-compatible, loading via packages.json instead of PEAR protocol');
            }

            $loader = new ArrayLoader();
            foreach ($packages as $data) {
                foreach ($data['versions'] as $rev) {
                    $rev['name'] = 'pear-'.$this->channel.'/'.$rev['name'];
                    $this->addPackage($loader->load($rev));
                }
            }

            return;
        } catch (\Exception $e) {
        }

        $this->fetchFromServer();
    }

    protected function initializeChannel()
    {
        $channelXML = $this->requestXml($this->url . "/channel.xml");
        if (!$this->channel) {
            $this->channel = $channelXML->getElementsByTagName("suggestedalias")->item(0)->nodeValue
                                    ?: $channelXML->getElementsByTagName("name")->item(0)->nodeValue;
        }

        self::$channelNames[$channelXML->getElementsByTagName("name")->item(0)->nodeValue] = $this->channel;
    }

    protected function fetchFromServer()
    {
        $categoryXML = $this->requestXml($this->url . "/rest/c/categories.xml");
        $categories = $categoryXML->getElementsByTagName("c");

        foreach ($categories as $category) {
            $link = '/' . ltrim($category->getAttribute("xlink:href"), '/');
            try {
                $packagesLink = str_replace("info.xml", "packagesinfo.xml", $link);
                $this->fetchPear2Packages($this->url . $packagesLink);
            } catch (TransportException $e) {
                if (false === strpos($e->getMessage(), '404')) {
                    throw $e;
                }
                $categoryLink = str_replace("info.xml", "packages.xml", $link);
                $this->fetchPearPackages($this->url . $categoryLink);
            }

        }
    }

    /**
     * @param   string $categoryLink
     * @throws  TransportException
     * @throws  InvalidArgumentException
     */
    private function fetchPearPackages($categoryLink)
    {
        $packagesXML = $this->requestXml($categoryLink);
        $packages = $packagesXML->getElementsByTagName('p');
        $loader = new ArrayLoader();
        foreach ($packages as $package) {
            $packageName = $package->nodeValue;
            $fullName = 'pear-'.$this->channel.'/'.$packageName;

            $packageLink = $package->getAttribute('xlink:href');
            $releaseLink = $this->url . str_replace("/rest/p/", "/rest/r/", $packageLink);
            $allReleasesLink = $releaseLink . "/allreleases2.xml";

            try {
                $releasesXML = $this->requestXml($allReleasesLink);
            } catch (TransportException $e) {
                if (strpos($e->getMessage(), '404')) {
                    continue;
                }
                throw $e;
            }

            $releases = $releasesXML->getElementsByTagName('r');

            foreach ($releases as $release) {
                /* @var $release \DOMElement */
                $pearVersion = $release->getElementsByTagName('v')->item(0)->nodeValue;

                $packageData = array(
                    'name' => $fullName,
                    'type' => 'library',
                    'dist' => array('type' => 'pear', 'url' => $this->url.'/get/'.$packageName.'-'.$pearVersion.".tgz"),
                    'version' => $pearVersion,
                    'autoload' => array(
                        'classmap' => array(''),
                    ),
                );

                try {
                    $deps = $this->rfs->getContents($this->url, $releaseLink . "/deps.".$pearVersion.".txt", false);
                } catch (TransportException $e) {
                    if (strpos($e->getMessage(), '404')) {
                        continue;
                    }
                    throw $e;
                }

                $packageData += $this->parseDependencies($deps);

                try {
                    $this->addPackage($loader->load($packageData));
                    if ($this->io->isVerbose()) {
                        $this->io->write('Loaded '.$packageData['name'].' '.$packageData['version']);
                    }
                } catch (\UnexpectedValueException $e) {
                    if ($this->io->isVerbose()) {
                        $this->io->write('Could not load '.$packageData['name'].' '.$packageData['version'].': '.$e->getMessage());
                    }
                    continue;
                }
            }
        }
    }

    /**
     * @param   array $data
     * @return  string
     */
    private function parseVersion(array $data)
    {
        if (!isset($data['min']) && !isset($data['max'])) {
            return '*';
        }
        $versions = array();
        if (isset($data['min'])) {
            $versions[] = '>=' . $data['min'];
        }
        if (isset($data['max'])) {
            $versions[] = '<=' . $data['max'];
        }
        return implode(',', $versions);
    }

    /**
     * @todo    Improve dependencies resolution of pear packages.
     * @param   array $options
     * @return  array
     */
    private function parseDependenciesOptions(array $depsOptions)
    {
        $data = array();
        foreach ($depsOptions as $name => $options) {
            // make sure single deps are wrapped in an array
            if (isset($options['name'])) {
                $options = array($options);
            }
            if ('php' == $name) {
                $data[$name] = $this->parseVersion($options);
            } elseif ('package' == $name) {
                foreach ($options as $key => $value) {
                    if (is_array($value)) {
                        $dataKey = $value['name'];
                        if (false === strpos($dataKey, '/')) {
                            $dataKey = $this->getChannelShorthand($value['channel']).'/'.$dataKey;
                        }
                        $data['pear-'.$dataKey] = $this->parseVersion($value);
                    }
                }
            } elseif ('extension' == $name) {
                foreach ($options as $key => $value) {
                    $dataKey = 'ext-' . $value['name'];
                    $data[$dataKey] = $this->parseVersion($value);
                }
            }
        }
        return $data;
    }

    /**
     * @param   string $deps
     * @return  array
     * @throws  InvalidArgumentException
     */
    private function parseDependencies($deps)
    {
        if (preg_match('((O:([0-9])+:"([^"]+)"))', $deps, $matches)) {
            if (strlen($matches[3]) == $matches[2]) {
                throw new \InvalidArgumentException("Invalid dependency data, it contains serialized objects.");
            }
        }
        $deps = (array) @unserialize($deps);
        unset($deps['required']['pearinstaller']);

        $depsData = array();
        if (!empty($deps['required'])) {
            $depsData['require'] = $this->parseDependenciesOptions($deps['required']);
        }

        if (!empty($deps['optional'])) {
            $depsData['suggest'] = $this->parseDependenciesOptions($deps['optional']);
        }

        return $depsData;
    }

    /**
     * @param   string $packagesLink
     * @return  void
     * @throws  InvalidArgumentException
     */
    private function fetchPear2Packages($packagesLink)
    {
        $loader = new ArrayLoader();
        $packagesXml = $this->requestXml($packagesLink);

        $informations = $packagesXml->getElementsByTagName('pi');
        foreach ($informations as $information) {
            $package = $information->getElementsByTagName('p')->item(0);

            $packageName = $package->getElementsByTagName('n')->item(0)->nodeValue;
            $fullName = 'pear-'.$this->channel.'/'.$packageName;
            $packageData = array(
                'name' => $fullName,
                'type' => 'library',
                'autoload' => array(
                    'classmap' => array(''),
                ),
            );
            $packageKeys = array('l' => 'license', 'd' => 'description');
            foreach ($packageKeys as $pear => $composer) {
                if ($package->getElementsByTagName($pear)->length > 0
                        && ($pear = $package->getElementsByTagName($pear)->item(0)->nodeValue)) {
                    $packageData[$composer] = $pear;
                }
            }

            $depsData = array();
            foreach ($information->getElementsByTagName('deps') as $depElement) {
                $depsVersion = $depElement->getElementsByTagName('v')->item(0)->nodeValue;
                $depsData[$depsVersion] = $this->parseDependencies(
                    $depElement->getElementsByTagName('d')->item(0)->nodeValue
                );
            }

            $releases = $information->getElementsByTagName('a')->item(0);
            if (!$releases) {
                continue;
            }

            $releases = $releases->getElementsByTagName('r');
            $packageUrl = $this->url . '/get/' . $packageName;
            foreach ($releases as $release) {
                $version = $release->getElementsByTagName('v')->item(0)->nodeValue;
                $releaseData = array(
                    'dist' => array(
                        'type' => 'pear',
                        'url' => $packageUrl . '-' . $version . '.tgz'
                    ),
                    'version' => $version
                );
                if (isset($depsData[$version])) {
                    $releaseData += $depsData[$version];
                }

                $package = $packageData + $releaseData;
                try {
                    $this->addPackage($loader->load($package));
                    if ($this->io->isVerbose()) {
                        $this->io->write('Loaded '.$package['name'].' '.$package['version']);
                    }
                } catch (\UnexpectedValueException $e) {
                    if ($this->io->isVerbose()) {
                        $this->io->write('Could not load '.$package['name'].' '.$package['version'].': '.$e->getMessage());
                    }
                    continue;
                }
            }
        }
    }

    /**
     * @param  string $url
     * @return DOMDocument
     */
    private function requestXml($url)
    {
        $content = $this->rfs->getContents($this->url, $url, false);
        if (!$content) {
            throw new \UnexpectedValueException('The PEAR channel at '.$url.' did not respond.');
        }
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->loadXML($content);

        return $dom;
    }

    private function getChannelShorthand($url)
    {
        if (!isset(self::$channelNames[$url])) {
            try {
                $channelXML = $this->requestXml('http://'.$url."/channel.xml");
                $shorthand = $channelXML->getElementsByTagName("suggestedalias")->item(0)->nodeValue
                    ?: $channelXML->getElementsByTagName("name")->item(0)->nodeValue;
                self::$channelNames[$url] = $shorthand;
            } catch (\Exception $e) {
                self::$channelNames[$url] = substr($url, 0, strpos($url, '.'));
            }
        }

        return self::$channelNames[$url];
    }
}
