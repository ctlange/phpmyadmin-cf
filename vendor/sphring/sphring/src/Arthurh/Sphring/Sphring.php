<?php
/**
 * Copyright (C) 2014 Arthur Halet
 *
 * This software is distributed under the terms and conditions of the 'MIT'
 * license which can be found in the file 'LICENSE' in this package distribution
 * or at 'http://opensource.org/licenses/MIT'.
 *
 * Author: Arthur Halet
 * Date: 14/10/2014
 */

/**
 * instropection composer:
 * $factoryComposer = new \Composer\Factory();
 * $composer = $factoryComposer->createComposer(new \Composer\IO\NullIO());
 * var_dump($composer->getPackage()->getRequires());
 */

namespace Arthurh\Sphring;

use Arhframe\Yamlarh\Yamlarh;
use Arthurh\Sphring\Enum\SphringEventEnum;
use Arthurh\Sphring\Enum\SphringYamlarhConstantEnum;
use Arthurh\Sphring\EventDispatcher\EventSphring;
use Arthurh\Sphring\EventDispatcher\SphringEventDispatcher;
use Arthurh\Sphring\Exception\SphringException;
use Arthurh\Sphring\Extender\Extender;
use Arthurh\Sphring\Logger\LoggerSphring;
use Arthurh\Sphring\Model\Bean\AbstractBean;
use Arthurh\Sphring\Model\Bean\FactoryBean;
use Arthurh\Sphring\Model\Bean\ProxyBean;
use Psr\Log\LoggerInterface;

/**
 * Class Sphring
 * @package Arthurh\Sphring
 */
class Sphring
{
    /**
     *
     */
    const DEFAULT_CONTEXT_FOLDER = 'sphring';
    /**
     *
     */
    const DEFAULT_CONTEXT_FILE = 'main.yml';
    /**
     * @var null|string
     */
    private $filename;
    /**
     * @var string
     */
    private $contextRoot;
    /**
     * @var array
     */
    private $context = array();
    /**
     * @var ProxyBean[]
     */
    private $beans = array();

    /**
     * @var SphringEventDispatcher
     *
     */
    private $sphringEventDispatcher;

    /**
     * @var Extender
     */
    private $extender;
    /**
     * @var string
     */
    private $rootProject;

    /**
     * @var FactoryBean
     */
    private $factoryBean;

    /**
     * @var Yamlarh
     */
    private $yamlarh;


    /**
     *
     */
    public function __construct($filename = null)
    {
        $this->yamlarh = new Yamlarh(null);
        $this->yamlarh->setParamaterKey(SphringYamlarhConstantEnum::PARAMETERNAME);
        if (empty($filename)) {
            $filename = '/' . self::DEFAULT_CONTEXT_FOLDER . '/' . self::DEFAULT_CONTEXT_FILE;
        }
        $this->filename = $filename;
        $this->sphringEventDispatcher = new SphringEventDispatcher($this);
        $this->extender = new Extender($this->sphringEventDispatcher);
        $this->factoryBean = new FactoryBean($this);
    }

    /**
     *
     */
    public function loadContext()
    {
        $this->beforeLoad();
        $this->sphringEventDispatcher->dispatch(SphringEventEnum::SPHRING_BEFORE_LOAD, new EventSphring($this));
        $this->getLogger()->info("Starting loading context...");
        $this->loadYamlarh($this->filename);
        if (empty($this->yamlarh->getFilename())) {
            throw new SphringException("Cannot load context, file '%s' doesn't exist in root project '%s'", $this->filename, $this->getRootProject());
        }
        $this->filename = realpath($this->yamlarh->getFilename());
        $this->contextRoot = dirname(realpath($this->yamlarh->getFilename()));
        $this->yamlarh->addAccessibleVariable(SphringYamlarhConstantEnum::CONTEXTROOT, $this->contextRoot);
        $this->getLogger()->info(sprintf("Loading context '%s' ...", realpath($this->yamlarh->getFilename())));
        $this->parseYaml();
        $this->extender->addExtendFromFile($this->contextRoot . '/' . $this->extender->getDefaultFilename());

        $this->extender->extend();
        $this->sphringEventDispatcher->dispatch(SphringEventEnum::SPHRING_START_LOAD, new EventSphring($this));
        $this->loadBeans();
        $this->sphringEventDispatcher->dispatchQueue();
        $this->sphringEventDispatcher->dispatch(SphringEventEnum::SPHRING_FINISHED_LOAD, new EventSphring($this));
    }

    private function parseYaml()
    {
        $this->sphringEventDispatcher->dispatch(SphringEventEnum::SPHRING_START_LOAD_CONTEXT, new EventSphring($this));
        if (empty($this->context)) {
            $this->context = $this->yamlarh->parse();
        }
        $this->sphringEventDispatcher->dispatch(SphringEventEnum::SPHRING_FINISHED_LOAD_CONTEXT, new EventSphring($this));
    }

    /**
     *
     */
    public function beforeLoad()
    {
        $this->sphringEventDispatcher->load();
    }

    /**
     * @return LoggerSphring
     */
    protected function getLogger()
    {
        return LoggerSphring::getInstance();
    }

    /**
     * @param string $filename
     * @return Yamlarh|null
     */
    public function loadYamlarh($filename)
    {
        if (is_file($filename)) {
            $this->yamlarh->setFileName($filename);
        }
        if (is_file($this->getRootProject() . $filename)) {
            $filename = $this->getRootProject() . $filename;
            $this->yamlarh->setFileName($filename);
        }
    }


    /**
     * @return string
     */
    public function getRootProject()
    {
        if (empty($this->rootProject)) {
            $this->rootProject = dirname($_SERVER['SCRIPT_FILENAME']);
        }
        return $this->rootProject;
    }

    /**
     * @param string $rootProject
     */
    public function setRootProject($rootProject)
    {
        $this->rootProject = $rootProject;
    }

    /**
     *
     */
    private function loadBeans()
    {
        foreach ($this->context as $beanId => $info) {
            if (!empty($this->beans[$beanId])) {
                continue;
            }
            $bean = $this->factoryBean->createBean($beanId, $this->context[$beanId]);
            $this->addBean($bean);

        }
    }

    /**
     * @param ProxyBean $bean
     */
    public function addBean(ProxyBean $bean)
    {
        $this->beans[$bean->__getBean()->getId()] = $bean;
        $bean->__getBean()->inject();
    }

    /**
     *
     */
    public function clear()
    {
        $this->sphringEventDispatcher->dispatch(SphringEventEnum::SPHRING_CLEAR, new EventSphring($this));
        $this->context = array();
        $this->beans = array();
    }

    /**
     * @param $beanId
     * @return object
     * @throws Exception\SphringException
     */
    public function getBean($beanId)
    {
        if (empty($this->beans[$beanId])) {
            throw new SphringException("Bean '%s' doesn't exist in the context.", $beanId);
        }
        return $this->beans[$beanId];
    }

    /**
     * @param $bean
     */
    public function removeBean($bean)
    {
        if ($bean instanceof AbstractBean) {
            $beanId = $bean->getId();
        } else if ($bean instanceof ProxyBean) {
            $beanId = $bean->__getBean()->getId();
        } else {
            $beanId = $bean;
        }
        if (empty($this->beans[$beanId])) {
            return;
        }
        unset($this->beans[$beanId]);
    }

    /**
     * @param LoggerInterface $logger
     */
    public function setLogger(LoggerInterface $logger)
    {
        LoggerSphring::getInstance()->setLogger($logger);

    }

    /**
     * @return mixed
     */
    public function getFilename()
    {
        return $this->filename;
    }

    /**
     * @param mixed $filename
     */
    public function setFilename($filename)
    {
        $this->filename = $filename;
    }

    /**
     * @return string
     */
    public function getContextRoot()
    {
        return $this->contextRoot;
    }

    /**
     * @return SphringEventDispatcher
     */
    public function getSphringEventDispatcher()
    {
        return $this->sphringEventDispatcher;
    }

    /**
     * @param SphringEventDispatcher $sphringEventDispatcher
     */
    public function setSphringEventDispatcher(SphringEventDispatcher $sphringEventDispatcher)
    {
        $this->sphringEventDispatcher = $sphringEventDispatcher;
        $this->sphringEventDispatcher->setSphring($this);
    }

    /**
     * @return Extender
     */
    public function getExtender()
    {
        return $this->extender;
    }

    /**
     * @param Extender $extender
     */
    public function setExtender(Extender $extender)
    {
        $this->extender = $extender;
        $this->extender->setSphringEventDispatcher($this->sphringEventDispatcher);
    }

    /**
     * @return Model\Bean\AbstractBean[]
     */
    public function getBeansObject()
    {
        $beans = [];
        foreach ($this->beans as $bean) {
            $beans[] = $bean->__getBean();
        }
        return $beans;
    }

    /**
     * @return FactoryBean
     */
    public function getFactoryBean()
    {
        return $this->factoryBean;
    }


    /**
     * @param $beanId
     * @return AbstractBean
     * @throws Exception\SphringException
     */
    public function getBeanObject($beanId)
    {
        if (!empty($this->beans[$beanId])) {
            return $this->beans[$beanId]->__getBean();
        }
        if (empty($this->context[$beanId])) {
            throw new SphringException("Bean '%s' doesn't exist in the context.", $beanId);
        }
        $bean = $this->factoryBean->createBean($beanId, $this->context[$beanId]);
        $this->addBean($bean);
        return $bean->__getBean();
    }

    /**
     * @return Yamlarh
     */
    public function getYamlarh()
    {
        return $this->yamlarh;
    }

    /**
     * @return array
     */
    public function getContext()
    {
        return $this->context;
    }

    /**
     * @param array $context
     */
    public function setContext($context)
    {
        $this->context = $context;
    }

    public function setComposerLockFile($composerLockFile)
    {
        $composerManager = $this->sphringEventDispatcher->getSphringBoot()->getComposerManager();
        $composerManager->setComposerLockFile($composerLockFile);
    }
}
