<?php
/**
 * Copyright © 2017 Ihor Vansach (ihor@magefan.com). All rights reserved.
 * See LICENSE.txt for license details (http://opensource.org/licenses/osl-3.0.php).
 */

namespace Magefan\Cli\Controller\Adminhtml\Index;

class Cli extends \Magento\Backend\App\Action
{
    const ADMIN_RESOURCE = 'Magefan_Cli::elements';

    /**
     * @var \Magento\Framework\View\Result\PageFactory
     */
    protected $resultPageFactory;

    /**
     * @var \Magento\Framework\Json\Helper\Data
     */
    protected $jsonHelper;

    /**
     * @var \Magento\Framework\Filesystem\DirectoryList
     */
    protected $dir;

    /**
     * @var \Magento\Framework\Filesystem\Io\File
     */
    protected $file;

    /**
     * Backend auth session
     *
     * @var \Magento\Backend\Model\Auth\Session
     */
    protected $authSession;

    /**
     * @var \Magento\Framework\App\Config\ScopeConfigInterface
     */
    protected $scopeConfig;

    /**
     * @var string
     */
    protected $logFileCommand;

    /**
     * @var string
     */
    protected $logFileResult;

    /**
     * @var string
     */
    protected $configKeepLog;

    /**
     * @var string
     */
    protected $configSendEmail;

    /**
     * @var string
     */
    protected $secretFile;

    /**
     * @var string
     */
    protected $configCmdPrefix;

    /**
     * @var string
     */
    protected $configCmdPostfix;

    /**
     * Constructor
     *
     * @param \Magento\Backend\App\Action\Context                $context
     * @param \Magento\Framework\View\Result\PageFactory         $resultPageFactory
     * @param \Magento\Framework\Json\Helper\Data                $jsonHelper
     * @param \Magento\Framework\Filesystem\DirectoryList        $dir
     * @param \Magento\Backend\Model\Auth\Session                $authSession
     * @param \Magento\Framework\Filesystem\Io\File              $file
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        \Magento\Backend\App\Action\Context $context,
        \Magento\Framework\View\Result\PageFactory $resultPageFactory,
        \Magento\Framework\Json\Helper\Data $jsonHelper,
        \Magento\Framework\Filesystem\DirectoryList $dir,
        \Magento\Backend\Model\Auth\Session $authSession,
        \Magento\Framework\Filesystem\Io\File $file,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
    ) {
        $this->resultPageFactory = $resultPageFactory;
        $this->jsonHelper = $jsonHelper;
        $this->dir = $dir;
        $this->file = $file;
        $this->authSession = $authSession;
        $this->scopeConfig = $scopeConfig;

        $this->configKeepLog = true;
        $this->configSendEmail = true;
        $this->configCmdPrefix = "/usr/bin/nice -n 19 /bin/bash -c '" ;
        $this->configCmdPostfix = "'";
        $this->secretFile = 'var/import-export-tmp-1kjnh23h987asd.txt';

        parent::__construct($context);
    }

    /**
     * Execute view action
     *
     * @return \Magento\Framework\Controller\ResultInterface
     */
    public function execute()
    {
        try {
            $this->validateUser();

            if(!file_exists($this->secretFile)){
                throw new \Exception(__('Error: The module is disabled.'), 1);
            }

            $command = $this->getRequest()->getParam('command');

            $blackCommands = ['admin:user','rm ','sudo'];
            foreach ($blackCommands as $bc) {
                if (strpos($command, $bc) !== false) {
                    throw new \Exception(__('Error: Cannot run this command due to security reason.'), 1);
                }
            }

            if (strpos($command, 'cd') === 0) {
                throw new \Exception(__('cd command is not supported.'), 1);
            }

            $this->logFile($command);
            exec($c = 'cd ' . $this->dir->getRoot() . ' && ' . $this->configCmdPrefix . $command . $this->configCmdPostfix . ' > ' . $this->logFileResult, $a, $b);
            $message = @file_get_contents($this->logFileResult);
            if (!$message) {
                $message = __('Command not found or error occurred.' . PHP_EOL);
            }else{
                $sanitizedCommand = preg_replace('/[^a-z0-9]+/', '-', strtolower($command));
                $this->logEmail($sanitizedCommand, $message, true);
                if(!$this->configKeepLog){
                    unlink($this->logFileCommand);
                    unlink($this->logFileResult);
                }
            }
        } catch (\Exception $e) {
            $message = $e->getMessage() . PHP_EOL;
        }

        $response = ['message' => nl2br($message)];

        return $this->getResponse()->representJson(
            $this->jsonHelper->jsonEncode($response)
        );
    }

    /**
     * @param $command
     *
     * @return string
     */
    protected function logFile($command)
    {
        $this->logFileCommand = '';
        $this->logFileResult  = '';

        if ( !empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } else if ( !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } else {
            $ip = $_SERVER['REMOTE_ADDR'];
        }

        try{
            $logDir = $this->dir->getPath('var') . '/mfcli';
            if ( !file_exists($logDir)) {
                $this->file->mkdir($logDir);
            }
            $timestamp         = date('Y-m-d_H:i:s');
            $sanitizedCommand = preg_replace('/[^a-z0-9]+/', '-', strtolower($command));

            $logFile           = $logDir . '/' . $timestamp . '_' . $sanitizedCommand . '.txt';
            $logHeader         = 'command: ' . $command . "\n";
            $logHeader         .= 'sanitized command: ' . $sanitizedCommand . "\n";
            $logHeader         .= 'administrator: ' . $this->authSession->getUser()->getUsername() . ' / ' . $this->authSession->getUser()->getEmail() . "\n";
            $logHeader         .= 'timestamp: ' . $timestamp . "\n" ;
            $logHeader         .= 'ip address: ' . $ip . "\n";

            file_put_contents($logFile, $logHeader);
            $this->logEmail($sanitizedCommand,$logHeader);
        }catch (\Exception $e) {
            return false;
        }

        $this->logFileCommand = $logFile;
        $this->logFileResult  = $logDir . '/' . $timestamp . '_' . $sanitizedCommand . '_result.txt';

        return true;
    }

    /**
     * @param      $subject
     * @param      $body
     * @param bool $result
     *
     * @return bool
     */
    protected function logEmail($subject, $body, $result = false)
    {
        try {
            if ($this->configSendEmail) {
                $subjectType = ($result ? 'result' : 'command');
                $email       = new \Zend_Mail();
                $email->setSubject("Magento 2 cli $subjectType: " . $subject);
                $email->setBodyText($body);
                $email->addTo($this->scopeConfig->getValue('trans_email/ident_custom2/email'), $this->scopeConfig->getValue('trans_email/ident_custom2/name'));
                $email->send();
            }
            return true;
        } catch (\Zend_Mail_Exception $e) {
            return false;
        }
    }

        /**
     * Validate current user password
     *
     * @return $this
     * @throws UserLockedException
     * @throws \Magento\Framework\Exception\AuthenticationException
     */
    protected function validateUser()
    {
        $password = $this->getRequest()->getParam(
            \Magento\User\Block\Role\Tab\Info::IDENTITY_VERIFICATION_PASSWORD_FIELD
        );
        $user = $this->authSession->getUser();
        $user->performIdentityCheck($password);

        return $this;
    }
}
