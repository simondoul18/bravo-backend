<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Stripe;

class StripePayoutCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'stripe:payout';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        Stripe\Stripe::setApiKey(env('STRIPE_TEST_SECRET'));
        $payout = \Stripe\Payout::create([
            'amount' => 0.5*100,
            'currency' => 'usd',
            'method' => 'instant',
            ], [
            'stripe_account' => 'acct_1LvKmGDCjUTRDmOW',
        ]);

        echo "<pre>";
        print_r($payout);
        exit;
    }
}
