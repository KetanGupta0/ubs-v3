<?php

namespace App\Console\Commands;

use App\Services\Shop\Orders;
use Illuminate\Console\Command;

/**
 * Orders nobody paid for lapse after a week (§4): the order and its payment
 * request are cancelled, an invoice issued for it is cancelled with them, and
 * any code it held is free for somebody else.
 */
class ExpireOrders extends Command
{
    protected $signature = 'shop:expire-orders';

    protected $description = 'Cancel shop orders left unpaid past their date';

    public function handle(Orders $orders): int
    {
        $count = $orders->expire();

        $this->info("Cancelled {$count} unpaid ".str('order')->plural($count).'.');

        return self::SUCCESS;
    }
}
