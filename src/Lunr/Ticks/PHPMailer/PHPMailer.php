<?php

/**
 * This file contains the PHPMailer class.
 *
 * SPDX-FileCopyrightText: Copyright 2025 Move Agency Group B.V., Zwolle, The Netherlands
 * SPDX-License-Identifier: MIT
 */

namespace Lunr\Ticks\PHPMailer;

use Lunr\Ticks\AnalyticsDetailLevel;
use Lunr\Ticks\EventLogging\EventLoggerInterface;
use Lunr\Ticks\TracingControllerInterface;
use Lunr\Ticks\TracingInfoInterface;
use PHPMailer\PHPMailer\PHPMailer as BaseMailer;

/**
 * PHPMailer class
 */
class PHPMailer extends BaseMailer
{

    /**
     * Shared instance of the event logger
     * @var EventLoggerInterface
     */
    protected readonly EventLoggerInterface $logger;

    /**
     * Shared instance of the info tracing controller
     * @var TracingControllerInterface&TracingInfoInterface
     */
    protected readonly TracingControllerInterface&TracingInfoInterface $controller;

    /**
     * Profiling level
     * @var AnalyticsDetailLevel
     */
    protected AnalyticsDetailLevel $level;

    /**
     * Start time of the mail sending
     * @var float
     */
    protected float $startTime;

    /**
     * Constructor.
     *
     * @param EventLoggerInterface                            $logger     PHP mail logger.
     * @param TracingControllerInterface&TracingInfoInterface $controller A tracing controller.
     * @param bool|null                                       $exceptions Should we throw external exceptions?
     */
    public function __construct(EventLoggerInterface $logger, TracingControllerInterface&TracingInfoInterface $controller, ?bool $exceptions = NULL)
    {
        $this->logger     = $logger;
        $this->controller = $controller;
        $this->level      = AnalyticsDetailLevel::Info;

        parent::__construct($exceptions);

        /**
         * The type of action_function is defined as string but it should be callable, so we ignore the phpstan warning
         * @phpstan-ignore assign.propertyType
         */
        $this->action_function = [ $this, 'afterSending' ];
    }

    /**
     * Destructor.
     */
    public function __destruct()
    {
        unset($this->startTime);
        unset($this->level);

        parent::__destruct();
    }

    /**
     * Set the analytics detail level
     *
     * @param AnalyticsDetailLevel $level The analytics detail level.
     *
     * @return void
     */
    public function setAnalyticsDetailLevel(AnalyticsDetailLevel $level): void
    {
        $this->level = $level;
    }

    /**
     * Create a message and send it.
     * Uses the sending method specified by $Mailer.
     *
     * @return bool false on error - See the ErrorInfo property for details of the error
     */
    public function send(): bool
    {
        $this->startTime = microtime(TRUE);

        $this->controller->startChildSpan();

        return parent::send();
    }

    /**
     * Callback after each mail send
     *
     * @param bool                                          $isSent  Result of the send action
     * @param array{0: string,1?: string}                   $to      Email addresses of the recipients
     * @param array<array{0: string,1?: string}>            $cc      Cc email addresses
     * @param array<array{0: string,1?: string}>            $bcc     Bcc email addresses
     * @param string                                        $subject The subject
     * @param string                                        $body    The email body
     * @param string                                        $from    Email address of sender
     * @param array{smtp_transaction_id?: bool|string|null} $extra   Extra information of possible use
     *
     * @return void
     */
    protected function afterSending(bool $isSent, array $to, array $cc, array $bcc, string $subject, string $body, string $from, array $extra): void
    {
        $tags = [
            'type'   => $this->Mailer,
            'status' => $isSent ? 'true' : 'false',
        ];

        $fields = [
            'url'          => $this->Host,
            'duration'     => microtime(TRUE) - $this->startTime,
            'ip'           => gethostbyname($this->Host),
            'traceID'      => $this->controller->getTraceId(),
            'spanID'       => $this->controller->getSpanId(),
            'parentSpanID' => $this->controller->getParentSpanId(),
        ];

        $options = [
            'from' => $from,
        ];

        if ($this->Mailer === 'smtp')
        {
            $options += [
                'SMTPHost'      => $this->Host,
                'SMTPPort'      => $this->Port,
                'SMTPHelo'      => $this->Helo,
                'SMTPSecure'    => $this->SMTPSecure,
                'SMTPAutoTLS'   => $this->SMTPAutoTLS,
                'SMTPAuth'      => $this->SMTPAuth,
                'SMTPUsername'  => $this->Username,
                'SMTPPassword'  => $this->Password,
                'SMTPKeepAlive' => $this->SMTPKeepAlive,
                'SMTPAuthType'  => $this->AuthType,
                'SMTPTimeout'   => $this->Timeout,
            ];

            $options += $this->SMTPOptions;
        }

        $options = json_encode($options + $extra);

        // If the profiling level is at least Detailed we want to store extra analytics
        if ($this->level->atleast(AnalyticsDetailLevel::Detailed))
        {
            $fields['request_headers'] = $this->prepareLogData($this->MIMEHeader);
            $fields['data']            = $this->prepareLogData($this->MIMEBody);
            $fields['options']         = $this->prepareLogData(is_bool($options) ? '' : $options);
        }

        $event = $this->logger->newEvent('mail');

        $event->addTags($tags);
        $event->addFields($fields);
        $event->recordTimestamp();
        $event->record();

        $this->controller->stopChildSpan();
    }

    /**
     * Prepare data according to loglevel.
     *
     * @param string $data Data to prepare for logging.
     *
     * @return string Prepare data to log.
     */
    private function prepareLogData(string $data): string
    {
        // If the profiling level is Detailed we want to log part of the info
        if ($this->level === AnalyticsDetailLevel::Detailed && strlen($data) > 512)
        {
            return substr($data, 0, 512) . '...';
        }

        return $data;
    }

}

?>
