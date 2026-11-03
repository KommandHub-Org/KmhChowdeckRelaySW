<?php

declare(strict_types=1);

namespace Kommandhub\ChowdeckRelaySW\Flow;

use Kommandhub\ChowdeckRelaySW\Flow\Event\DeliveryDeliveredEvent;
use Kommandhub\ChowdeckRelaySW\Flow\Event\DeliveryFailedEvent;
use Kommandhub\ChowdeckRelaySW\Flow\Event\DeliveryRequestedEvent;
use Shopware\Core\Content\Flow\Dispatching\Action\SendMailAction;
use Shopware\Core\Content\Flow\FlowCollection;
use Shopware\Core\Content\MailTemplate\Aggregate\MailTemplateType\MailTemplateTypeCollection;
use Shopware\Core\Content\MailTemplate\MailTemplateCollection;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Installs every flow the plugin needs, so nothing has to be set up by hand
 * in Flow Builder (spec §13.7), all active:
 *
 *  - "Chowdeck: rider on the way email" — tracking link (and PIN) to the
 *    customer once a rider is requested;
 *  - "Chowdeck: rider request failed alert" — Chowdeck's reason to the shop
 *    admin;
 *  - "Chowdeck: delivered email" — confirms the delivery to the customer.
 *
 * There is deliberately no flow that requests a rider: a created delivery
 * calls a rider at once, so dispatch stays manual (decision 3 Nov 2026).
 *
 * Each flow has a fixed id and is created only when missing, so a flow the
 * merchant edited, switched off or deleted is never touched or brought back
 * by an update. Mail templates are created together with their flow.
 */
class FlowInstaller
{
    public const REQUESTED_MAIL_TYPE = 'kmh_chowdeck_delivery_requested';

    public const FAILED_MAIL_TYPE = 'kmh_chowdeck_delivery_failed';

    public const DELIVERED_MAIL_TYPE = 'kmh_chowdeck_delivery_delivered';

    public const MAIL_TYPES = [self::REQUESTED_MAIL_TYPE, self::FAILED_MAIL_TYPE, self::DELIVERED_MAIL_TYPE];

    /**
     * @param EntityRepository<FlowCollection> $flowRepository
     * @param EntityRepository<MailTemplateTypeCollection> $mailTemplateTypeRepository
     * @param EntityRepository<MailTemplateCollection> $mailTemplateRepository
     */
    public function __construct(
        private readonly EntityRepository $flowRepository,
        private readonly EntityRepository $mailTemplateTypeRepository,
        private readonly EntityRepository $mailTemplateRepository,
    ) {
    }

    /**
     * Fixed ids of the flows this installer owns.
     *
     * @return list<string>
     */
    public static function flowIds(): array
    {
        return array_map(static fn (string $key): string => Uuid::fromStringToHex('kmh_chowdeck.flow.' . $key), ['requested', 'failed', 'delivered']);
    }

    public function install(Context $context): void
    {
        [$requestedId, $failedId, $deliveredId] = self::flowIds();

        if (!$this->flowExists($requestedId, $context)) {
            $templateId = $this->createMailTemplate(self::REQUESTED_MAIL_TYPE, ['en-GB' => 'Chowdeck: rider on the way', 'de-DE' => 'Chowdeck: Fahrer unterwegs'], self::requestedMail(), $context);
            $this->createFlow(
                $requestedId,
                'Chowdeck: rider on the way email',
                DeliveryRequestedEvent::EVENT_NAME,
                'Emails the customer the tracking link and delivery PIN when a Chowdeck rider is requested.',
                self::mailSequence($templateId, ['type' => 'default', 'data' => []]),
                $context
            );
        }

        if (!$this->flowExists($failedId, $context)) {
            $templateId = $this->createMailTemplate(self::FAILED_MAIL_TYPE, ['en-GB' => 'Chowdeck: rider request failed', 'de-DE' => 'Chowdeck: Fahreranfrage fehlgeschlagen'], self::failedMail(), $context);
            $this->createFlow(
                $failedId,
                'Chowdeck: rider request failed alert',
                DeliveryFailedEvent::EVENT_NAME,
                'Emails the shop admin when a Chowdeck rider could not be requested.',
                self::mailSequence($templateId, ['type' => 'admin', 'data' => []]),
                $context
            );
        }

        if (!$this->flowExists($deliveredId, $context)) {
            $templateId = $this->createMailTemplate(self::DELIVERED_MAIL_TYPE, ['en-GB' => 'Chowdeck: delivered', 'de-DE' => 'Chowdeck: zugestellt'], self::deliveredMail(), $context);
            $this->createFlow(
                $deliveredId,
                'Chowdeck: delivered email',
                DeliveryDeliveredEvent::EVENT_NAME,
                'Emails the customer when Chowdeck reports the order as delivered.',
                self::mailSequence($templateId, ['type' => 'default', 'data' => []]),
                $context
            );
        }
    }

    private function flowExists(string $id, Context $context): bool
    {
        return $this->flowRepository->searchIds(new Criteria([$id]), $context)->firstId() !== null;
    }

    /**
     * @param array{actionName: string, config: array<string, mixed>} $sequence
     */
    private function createFlow(string $id, string $name, string $eventName, string $description, array $sequence, Context $context): void
    {
        $this->flowRepository->create([[
            'id' => $id,
            'name' => $name,
            'eventName' => $eventName,
            'priority' => 1,
            'active' => true,
            'description' => $description,
            'sequences' => [['id' => Uuid::randomHex(), 'position' => 1, 'displayGroup' => 1] + $sequence],
        ]], $context);
    }

    /**
     * @param array{type: string, data: array<mixed>} $recipient
     *
     * @return array{actionName: string, config: array<string, mixed>}
     */
    private static function mailSequence(string $mailTemplateId, array $recipient): array
    {
        return [
            'actionName' => SendMailAction::ACTION_NAME,
            'config' => [
                'mailTemplateId' => $mailTemplateId,
                'documentTypeIds' => [],
                'recipient' => $recipient,
                'replyTo' => null,
            ],
        ];
    }

    /**
     * @param array<string, string> $typeNames
     * @param array<string, array<string, string>> $translations
     */
    private function createMailTemplate(string $technicalName, array $typeNames, array $translations, Context $context): string
    {
        $criteria = (new Criteria())->addFilter(new EqualsFilter('technicalName', $technicalName))->setLimit(1);
        $typeId = $this->mailTemplateTypeRepository->searchIds($criteria, $context)->firstId();

        if ($typeId === null) {
            $typeId = Uuid::randomHex();
            $this->mailTemplateTypeRepository->create([[
                'id' => $typeId,
                'technicalName' => $technicalName,
                'availableEntities' => ['order' => 'order'],
                'translations' => array_map(static fn (string $name): array => ['name' => $name], $typeNames),
            ]], $context);
        }

        $templateId = Uuid::randomHex();
        $this->mailTemplateRepository->create([[
            'id' => $templateId,
            'mailTemplateTypeId' => $typeId,
            'systemDefault' => false,
            'translations' => array_map(
                static fn (array $translation): array => ['senderName' => '{{ salesChannel.translated.name }}'] + $translation,
                $translations,
            ),
        ]], $context);

        return $templateId;
    }

    /**
     * @return array<string, array<string, string>>
     */
    private static function requestedMail(): array
    {
        return [
            'en-GB' => [
                'subject' => 'A rider is on the way with your order {{ order.orderNumber }}',
                'description' => 'Sent to the customer when a Chowdeck rider is requested.',
                'contentHtml' => '<p>Hello {{ order.orderCustomer.firstName }},</p>'
                    . '<p>A Chowdeck rider is collecting your order <strong>{{ order.orderNumber }}</strong> and will bring it to you today.</p>'
                    . '{% if deliveryPin %}<p>Your delivery PIN: <strong>{{ deliveryPin }}</strong>. Give it to the rider when the order arrives.</p>{% endif %}'
                    . '{% if trackingUrl %}<p><a href="{{ trackingUrl }}">Track your delivery</a></p>{% endif %}',
                'contentPlain' => "Hello {{ order.orderCustomer.firstName }},\n\n"
                    . "A Chowdeck rider is collecting your order {{ order.orderNumber }} and will bring it to you today.\n\n"
                    . "{% if deliveryPin %}Your delivery PIN: {{ deliveryPin }}. Give it to the rider when the order arrives.\n\n{% endif %}"
                    . '{% if trackingUrl %}Track your delivery: {{ trackingUrl }}{% endif %}',
            ],
            'de-DE' => [
                'subject' => 'Ein Fahrer ist mit Ihrer Bestellung {{ order.orderNumber }} unterwegs',
                'description' => 'Wird an den Kunden gesendet, wenn ein Chowdeck-Fahrer angefordert ist.',
                'contentHtml' => '<p>Hallo {{ order.orderCustomer.firstName }},</p>'
                    . '<p>Ein Chowdeck-Fahrer holt Ihre Bestellung <strong>{{ order.orderNumber }}</strong> ab und bringt sie Ihnen heute.</p>'
                    . '{% if deliveryPin %}<p>Ihre Liefer-PIN: <strong>{{ deliveryPin }}</strong>. Nennen Sie sie dem Fahrer bei der Übergabe.</p>{% endif %}'
                    . '{% if trackingUrl %}<p><a href="{{ trackingUrl }}">Lieferung verfolgen</a></p>{% endif %}',
                'contentPlain' => "Hallo {{ order.orderCustomer.firstName }},\n\n"
                    . "Ein Chowdeck-Fahrer holt Ihre Bestellung {{ order.orderNumber }} ab und bringt sie Ihnen heute.\n\n"
                    . "{% if deliveryPin %}Ihre Liefer-PIN: {{ deliveryPin }}. Nennen Sie sie dem Fahrer bei der Übergabe.\n\n{% endif %}"
                    . '{% if trackingUrl %}Lieferung verfolgen: {{ trackingUrl }}{% endif %}',
            ],
        ];
    }

    /**
     * @return array<string, array<string, string>>
     */
    private static function failedMail(): array
    {
        return [
            'en-GB' => [
                'subject' => 'Chowdeck rider for order {{ order.orderNumber }} could not be requested',
                'description' => 'Sent to the shop admin when requesting a Chowdeck rider fails.',
                'contentHtml' => '<p>Requesting a Chowdeck rider for order <strong>{{ order.orderNumber }}</strong> failed.</p>'
                    . '<p>Chowdeck said: <strong>{{ errorMessage }}</strong></p>'
                    . '<p>Fix the cause (for example top up your Chowdeck wallet), then open the order in the Administration and click <em>Request rider</em>.</p>',
                'contentPlain' => "Requesting a Chowdeck rider for order {{ order.orderNumber }} failed.\n\n"
                    . "Chowdeck said: {{ errorMessage }}\n\n"
                    . 'Fix the cause (for example top up your Chowdeck wallet), then open the order in the Administration and click Request rider.',
            ],
            'de-DE' => [
                'subject' => 'Chowdeck-Fahrer für Bestellung {{ order.orderNumber }} konnte nicht angefordert werden',
                'description' => 'Wird an den Shop-Admin gesendet, wenn die Anforderung eines Chowdeck-Fahrers fehlschlägt.',
                'contentHtml' => '<p>Die Anforderung eines Chowdeck-Fahrers für Bestellung <strong>{{ order.orderNumber }}</strong> ist fehlgeschlagen.</p>'
                    . '<p>Chowdeck meldet: <strong>{{ errorMessage }}</strong></p>'
                    . '<p>Beheben Sie die Ursache (z. B. Chowdeck-Wallet aufladen) und klicken Sie dann in der Bestellung in der Administration auf <em>Fahrer anfordern</em>.</p>',
                'contentPlain' => "Die Anforderung eines Chowdeck-Fahrers für Bestellung {{ order.orderNumber }} ist fehlgeschlagen.\n\n"
                    . "Chowdeck meldet: {{ errorMessage }}\n\n"
                    . 'Beheben Sie die Ursache (z. B. Chowdeck-Wallet aufladen) und klicken Sie dann in der Bestellung in der Administration auf Fahrer anfordern.',
            ],
        ];
    }

    /**
     * @return array<string, array<string, string>>
     */
    private static function deliveredMail(): array
    {
        return [
            'en-GB' => [
                'subject' => 'Your order {{ order.orderNumber }} has been delivered',
                'description' => 'Sent to the customer when Chowdeck reports the order as delivered.',
                'contentHtml' => '<p>Hello {{ order.orderCustomer.firstName }},</p>'
                    . '<p>Your order <strong>{{ order.orderNumber }}</strong> has been delivered.</p>'
                    . '<p>Thank you for shopping with us.</p>',
                'contentPlain' => "Hello {{ order.orderCustomer.firstName }},\n\n"
                    . "Your order {{ order.orderNumber }} has been delivered.\n\n"
                    . 'Thank you for shopping with us.',
            ],
            'de-DE' => [
                'subject' => 'Ihre Bestellung {{ order.orderNumber }} wurde zugestellt',
                'description' => 'Wird an den Kunden gesendet, wenn Chowdeck die Bestellung als zugestellt meldet.',
                'contentHtml' => '<p>Hallo {{ order.orderCustomer.firstName }},</p>'
                    . '<p>Ihre Bestellung <strong>{{ order.orderNumber }}</strong> wurde zugestellt.</p>'
                    . '<p>Vielen Dank für Ihren Einkauf.</p>',
                'contentPlain' => "Hallo {{ order.orderCustomer.firstName }},\n\n"
                    . "Ihre Bestellung {{ order.orderNumber }} wurde zugestellt.\n\n"
                    . 'Vielen Dank für Ihren Einkauf.',
            ],
        ];
    }
}
