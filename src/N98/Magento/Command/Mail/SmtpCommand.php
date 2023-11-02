<?php

namespace N98\Magento\Command\Mail;

use Laminas\Mail\Message;
use Laminas\Mail\Transport\Smtp as SmtpTransport;
use Laminas\Mail\Transport\SmtpOptions;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Store\Api\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use N98\Magento\Command\Config\AbstractConfigCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Validation;

/**
 * Class SmtpCommand
 * @package N98\Magento\Command\Mail
 */
class SmtpCommand extends AbstractConfigCommand
{
    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var TransportBuilder
     */
    private $transportBuilder;

    /**
     * Setup
     *
     * @return void
     */
    protected function configure()
    {
        $this
            ->setName('mail:smtp')
            ->addOption('to', null, InputOption::VALUE_OPTIONAL, 'Email address to send test email')
            ->setDescription('Check magento2 SMTP configurations and send test email.');
    }

    public function inject(StoreManagerInterface $storeManager, TransportBuilder $transportBuilder)
    {
        $this->storeManager = $storeManager;
        $this->transportBuilder = $transportBuilder;
    }

    /**
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     * @return int
     * @throws \Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->detectMagento($output, true);
        if (!$this->initMagento()) {
            return Command::FAILURE;
        }

        $to = $input->getOption('to');

        if (!empty($to)) {
            if (!$this->isValidEmail($to)) {
                $output->writeln('<error>Please enter a valid email address.</error>');
                return Command::FAILURE;
            }

            $this->sendEmail($to);
        } else {
            $data = $this->getSmtpConfig();

            $this->getHelper('table')
                ->setHeaders(
                    ['store', 'disable', 'transport', 'host', 'port', 'username', 'password', 'auth', 'ssl']
                )
                ->renderByFormat($output, $data, null);
        }

        return Command::SUCCESS;
    }

    private function getSmtpConfig()
    {
        $data = [];
        $scope = \Magento\Store\Model\ScopeInterface::SCOPE_STORE;
        $stores = $this->storeManager->getStores();

        if (!empty($stores)) {
            /** @var StoreInterface $store */
            foreach ($stores as $store) {
                $data[] = [
                    'store' => $store->getCode(),
                    'disable' => $this->getScopeConfigValue('system/smtp/disable', $scope),
                    'transport' => $this->getScopeConfigValue('system/smtp/transport', $scope),
                    'host' => $this->getScopeConfigValue('system/smtp/host', $scope),
                    'port' => $this->getScopeConfigValue('system/smtp/port', $scope),
                    'username' => $this->getScopeConfigValue('system/smtp/username', $scope),
                    'password' => $this->getScopeConfigValue('system/smtp/password', $scope),
                    'auth' => $this->getScopeConfigValue('system/smtp/auth', $scope),
                    'ssl' => $this->getScopeConfigValue('system/smtp/ssl', $scope),
                ];
            }
        }

        return $data;
    }

    private function sendEmail($to)
    {
        $data = $this->getSmtpConfig();
        if (!empty($data)) {
            foreach ($data as $smtpData) {
                $sentEmailMessage = "Email sent from store " . ($smtpData['store'] ?? 'no-name') . "\n";
                try {
                    $from = $smtpData['username'] ?? null;
                    if (!$this->isValidEmail($from)) {
                        $from = $this->getScopeConfigValue(
                            'trans_email/ident_general/email',
                            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
                        );
                    }

                    $message = new Message();
                    $message->addTo($to);
                    $message->addFrom($from);
                    $message->setSubject('Test email from n98-magerun2 mail:smtp');
                    $message->setBody('Test content');

                    $optionsArray = [];

                    if (!empty($smtpData['store'])) {
                        $optionsArray['name'] = $smtpData['store'];
                    }

                    if (!empty($smtpData['host'])) {
                        $optionsArray['host'] = $smtpData['host'];
                    }

                    if (!empty($smtpData['port'])) {
                        $optionsArray['port'] = $smtpData['port'];
                    }

                    $optionsArray['connection_class'] = 'login';
                    if (!empty($smtpData['auth'])) {
                        $optionsArray['connection_class'] = $smtpData['auth'];
                    }

                    if (!empty($smtpData['username'])) {
                        $optionsArray['connection_config']['username'] = $smtpData['username'];
                    }

                    if (!empty($smtpData['password'])) {
                        $optionsArray['connection_config']['password'] = $smtpData['password'];
                    }

                    if (!empty($smtpData['ssl'])) {
                        $optionsArray['connection_config']['ssl'] = $smtpData['ssl'];
                    }

                    $transport = new SmtpTransport();
                    $options = new SmtpOptions($optionsArray);
                    $transport->setOptions($options);
                    $transport->send($message);
                    echo $sentEmailMessage;
                }
                catch (\Exception $e) {
                    throw $e;
                }
            }
        }
    }

    private function isValidEmail($email)
    {
        $validator = Validation::createValidator();
        $errors = $validator->validate($email, [
            new Email(),
        ]);

        return !count($errors);
    }
}
