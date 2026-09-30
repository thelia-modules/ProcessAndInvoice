<?php

declare(strict_types=1);

/*
 * This file is part of the Thelia package.
 * http://www.thelia.net
 *
 * (c) OpenStudio <info@thelia.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ProcessAndInvoice\Hook;

use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Event\Hook\HookRenderEvent;
use Thelia\Core\Hook\BaseHook;
use Thelia\Core\Template\Parser\ParserResolver;
use Thelia\Tools\TokenProvider;

/**
 * Adds the "Process paid orders" button above the order list of the back office.
 */
class OrdersHook extends BaseHook
{
    public function __construct(
        private readonly TokenProvider $tokenProvider,
        ?EventDispatcherInterface $dispatcher = null,
        ?ParserResolver $parserResolver = null,
    ) {
        parent::__construct($dispatcher, $parserResolver);
    }

    /**
     * @return array<string, list<array{type: string, method: string}>>
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'orders.top' => [
                ['type' => 'back', 'method' => 'onOrdersTop'],
            ],
        ];
    }

    public function onOrdersTop(HookRenderEvent $event): void
    {
        $event->add($this->render('ProcessAndInvoice/orders_top.html.twig', [
            'process_token' => $this->tokenProvider->assignToken(),
        ]));
    }
}
