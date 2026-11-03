<?php

declare(strict_types=1);

namespace Kommandhub\ChowdeckRelaySW\Core\Content\Delivery;

use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

class DeliveryEntity extends Entity
{
    use EntityIdTrait;

    protected string $orderId;

    protected string $orderVersionId;

    protected string $orderDeliveryId;

    protected string $orderDeliveryVersionId;

    protected string $salesChannelId;

    protected string $status;

    protected ?int $feeId = null;

    protected ?\DateTimeInterface $quotedAt = null;

    protected ?float $quotedAmount = null;

    protected ?float $chargedAmount = null;

    protected ?string $currency = null;

    protected ?string $reference = null;

    protected ?int $chowdeckId = null;

    protected ?string $trackingUrl = null;

    protected ?int $deliveryPin = null;

    /**
     * @var array<mixed>|null
     */
    protected ?array $rider = null;

    protected ?float $refundAmount = null;

    protected ?string $errorCode = null;

    protected ?string $errorMessage = null;

    protected ?\DateTimeInterface $requestedAt = null;

    protected ?\DateTimeInterface $deliveredAt = null;

    protected ?\DateTimeInterface $cancelledAt = null;

    protected ?OrderEntity $order = null;

    protected ?OrderDeliveryEntity $orderDelivery = null;

    public function getOrderId(): string
    {
        return $this->orderId;
    }

    public function setOrderId(string $orderId): void
    {
        $this->orderId = $orderId;
    }

    public function getOrderVersionId(): string
    {
        return $this->orderVersionId;
    }

    public function setOrderVersionId(string $orderVersionId): void
    {
        $this->orderVersionId = $orderVersionId;
    }

    public function getOrderDeliveryId(): string
    {
        return $this->orderDeliveryId;
    }

    public function setOrderDeliveryId(string $orderDeliveryId): void
    {
        $this->orderDeliveryId = $orderDeliveryId;
    }

    public function getOrderDeliveryVersionId(): string
    {
        return $this->orderDeliveryVersionId;
    }

    public function setOrderDeliveryVersionId(string $orderDeliveryVersionId): void
    {
        $this->orderDeliveryVersionId = $orderDeliveryVersionId;
    }

    public function getSalesChannelId(): string
    {
        return $this->salesChannelId;
    }

    public function setSalesChannelId(string $salesChannelId): void
    {
        $this->salesChannelId = $salesChannelId;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
    }

    public function getFeeId(): ?int
    {
        return $this->feeId;
    }

    public function setFeeId(?int $feeId): void
    {
        $this->feeId = $feeId;
    }

    public function getQuotedAt(): ?\DateTimeInterface
    {
        return $this->quotedAt;
    }

    public function setQuotedAt(?\DateTimeInterface $quotedAt): void
    {
        $this->quotedAt = $quotedAt;
    }

    public function getQuotedAmount(): ?float
    {
        return $this->quotedAmount;
    }

    public function setQuotedAmount(?float $quotedAmount): void
    {
        $this->quotedAmount = $quotedAmount;
    }

    public function getChargedAmount(): ?float
    {
        return $this->chargedAmount;
    }

    public function setChargedAmount(?float $chargedAmount): void
    {
        $this->chargedAmount = $chargedAmount;
    }

    public function getCurrency(): ?string
    {
        return $this->currency;
    }

    public function setCurrency(?string $currency): void
    {
        $this->currency = $currency;
    }

    public function getReference(): ?string
    {
        return $this->reference;
    }

    public function setReference(?string $reference): void
    {
        $this->reference = $reference;
    }

    public function getChowdeckId(): ?int
    {
        return $this->chowdeckId;
    }

    public function setChowdeckId(?int $chowdeckId): void
    {
        $this->chowdeckId = $chowdeckId;
    }

    public function getTrackingUrl(): ?string
    {
        return $this->trackingUrl;
    }

    public function setTrackingUrl(?string $trackingUrl): void
    {
        $this->trackingUrl = $trackingUrl;
    }

    public function getDeliveryPin(): ?int
    {
        return $this->deliveryPin;
    }

    public function setDeliveryPin(?int $deliveryPin): void
    {
        $this->deliveryPin = $deliveryPin;
    }

    /**
     * @return array<mixed>|null
     */
    public function getRider(): ?array
    {
        return $this->rider;
    }

    /**
     * @param array<mixed>|null $rider
     */
    public function setRider(?array $rider): void
    {
        $this->rider = $rider;
    }

    public function getRefundAmount(): ?float
    {
        return $this->refundAmount;
    }

    public function setRefundAmount(?float $refundAmount): void
    {
        $this->refundAmount = $refundAmount;
    }

    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    public function setErrorCode(?string $errorCode): void
    {
        $this->errorCode = $errorCode;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function setErrorMessage(?string $errorMessage): void
    {
        $this->errorMessage = $errorMessage;
    }

    public function getRequestedAt(): ?\DateTimeInterface
    {
        return $this->requestedAt;
    }

    public function setRequestedAt(?\DateTimeInterface $requestedAt): void
    {
        $this->requestedAt = $requestedAt;
    }

    public function getDeliveredAt(): ?\DateTimeInterface
    {
        return $this->deliveredAt;
    }

    public function setDeliveredAt(?\DateTimeInterface $deliveredAt): void
    {
        $this->deliveredAt = $deliveredAt;
    }

    public function getCancelledAt(): ?\DateTimeInterface
    {
        return $this->cancelledAt;
    }

    public function setCancelledAt(?\DateTimeInterface $cancelledAt): void
    {
        $this->cancelledAt = $cancelledAt;
    }

    public function getOrder(): ?OrderEntity
    {
        return $this->order;
    }

    public function setOrder(?OrderEntity $order): void
    {
        $this->order = $order;
    }

    public function getOrderDelivery(): ?OrderDeliveryEntity
    {
        return $this->orderDelivery;
    }

    public function setOrderDelivery(?OrderDeliveryEntity $orderDelivery): void
    {
        $this->orderDelivery = $orderDelivery;
    }
}
