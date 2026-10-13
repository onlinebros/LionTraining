<?php

namespace App\Services\Vendor;

use App\Models\VendorLead;

/**
 * What to tell a buyer about their payment, and what they should look out for
 * to finish it — one source for the completion page and the buyer email, so
 * the two can never say different things.
 *
 * The rule the wording follows: only PAID says "order". Every other state says
 * plainly that nothing has been ordered and nothing ships until the vendor has
 * the funds, names the payment option the buyer chose, and tells them what a
 * FINISHED payment looks like with that option, so they can check for
 * themselves.
 *
 * The provider facts (time limits, amount ranges, what each sends) are from
 * Stripe's payment-method docs and the providers' own help pages, checked
 * October 2026:
 *   docs.stripe.com/payments/affirm, …/klarna, …/ach-direct-debit,
 *   …/cash-app-pay, …/amazon-pay, …/receipts
 *   klarna.com/us/customer-service/what-is-financing-and-how-does-it-work
 * Revisit them if a provider changes its terms; keep claims to what they say.
 */
final class PaymentGuidance
{
    /** @param  list<string>  $steps */
    private function __construct(
        public readonly string $tone,       // ok | warn | alert
        public readonly string $subject,
        public readonly string $title,
        public readonly string $intro,
        public readonly ?string $option,    // the payment option the buyer chose, in words
        public readonly string $stepsHeading,
        public readonly array $steps,
        public readonly bool $canRetry,
    ) {}

    /** @param  array<string,mixed>  $vendor */
    public static function for(PaymentState $payment, VendorLead $lead, array $vendor): self
    {
        return self::forState($payment->state, $payment->method, $payment->abandoned, $payment->nextAction, $lead, $vendor);
    }

    /** @param  array<string,mixed>  $vendor */
    public static function forState(
        string $state,
        ?string $method,
        bool $abandoned,
        ?string $nextAction,
        VendorLead $lead,
        array $vendor,
    ): self {
        $seller  = (string) ($vendor['name'] ?? 'The seller');
        $ref     = (string) $lead->public_ref;
        $item    = max(1, (int) $lead->quantity).' × '.$lead->productName();
        $o       = self::option($method, (int) $lead->amount_total, $seller);
        $name    = $o['name'];
        $receipt = "{$seller} emails you a payment receipt once the funds are received. "
            ."Until you have that receipt, your order has not gone through.";

        if ($state === PaymentState::PAID) {
            return new self('ok',
                subject: "Order {$ref} confirmed",
                title: 'Thank you, '.$lead->first_name,
                intro: "Payment received — your order for {$item} is placed. {$seller} will confirm by email and arrange delivery.",
                option: $o['label'],
                stepsHeading: 'What happens next',
                steps: array_merge(
                    ["{$seller} confirms your order directly by email, and you receive a payment receipt."],
                    $o['paid'],
                    [
                        'They arrange shipping and provide tracking.',
                        ($lead->member?->name ?? 'Your partner').' stays your point of contact for anything to do with the system.',
                    ],
                ),
                canRetry: false,
            );
        }

        if ($state === PaymentState::PENDING) {
            if ($method === 'us_bank_account') {
                return new self('warn',
                    subject: "Order {$ref} is waiting for your bank payment to clear",
                    title: 'Your bank payment is on its way — not received yet',
                    intro: "Your order for {$item} is NOT confirmed yet. {$seller} ships only once the bank payment has cleared and the funds are received.",
                    option: $o['label'],
                    stepsHeading: 'What to look out for',
                    steps: [
                        'Bank (ACH) payments take up to 4 business days to clear.',
                        'Keep enough money in the account until the payment has cleared. If your bank returns it — for insufficient funds, a closed account or a block on debits — the order is not placed.',
                        $receipt,
                        "Please don't pay again while this one is clearing; that could charge you twice.",
                    ],
                    canRetry: false,
                );
            }

            return new self('warn',
                subject: "Order {$ref} is waiting for payment",
                title: 'Your payment is being processed — not received yet',
                intro: "Your order for {$item} is NOT confirmed yet. {$seller} ships only once the payment has been received.",
                option: $o['label'],
                stepsHeading: 'What to look out for',
                steps: [
                    $receipt,
                    "Please don't pay again while this one is processing; that could charge you twice.",
                ],
                canRetry: false,
            );
        }

        if ($state === PaymentState::ACTION) {
            if ($nextAction === 'verify_with_microdeposits') {
                return new self('warn',
                    subject: "Action needed: verify your bank account to complete order {$ref}",
                    title: 'Verify your bank account to finish paying',
                    intro: "Your order for {$item} is NOT placed yet. Nothing ships until your bank account is verified, the payment clears and {$seller} has the funds.",
                    option: self::option('us_bank_account', 0, $seller)['label'],
                    stepsHeading: 'What to do',
                    steps: [
                        'Within 1–2 business days you will see a small deposit in your account: either one $0.01 deposit with a 6-digit code starting "SM", or two small deposits labelled "ACCTVERIFY".',
                        "Enter the code or amounts using the verification link in the email Stripe sends you on {$seller}'s behalf.",
                        'You have 10 days to verify. After that the payment lapses and you would need to start again.',
                        'Once verified, the payment takes up to 4 business days to clear.',
                        $receipt,
                    ],
                    canRetry: false,
                );
            }

            return new self('warn',
                subject: $name
                    ? "Action needed: your {$name} payment for order {$ref} isn't finished"
                    : "Action needed: your payment for order {$ref} isn't finished",
                title: $name ? "Your {$name} payment isn't finished" : "Your payment isn't finished",
                intro: "No order has been placed and nothing will ship. {$o['shipsWhen']}",
                option: $o['label'],
                stepsHeading: 'How to finish',
                steps: array_merge($o['finish'], $o['terms'], $o['expiry'], [
                    $receipt,
                    'Use the button below to go back to checkout and finish — or choose a different way to pay.',
                ]),
                canRetry: true,
            );
        }

        // We could not ask Stripe. Neither "placed" nor "failed" would be honest.
        if ($state !== PaymentState::NOT_PAID) {
            return new self('warn',
                subject: "Order {$ref}: payment not yet confirmed",
                title: "We're still confirming your payment",
                intro: "We could not confirm your payment just now, so please don't treat this page as an order confirmation. {$seller} ships only once the funds are received.",
                option: null,
                stepsHeading: 'What to look out for',
                steps: [
                    'Refresh this page in a few minutes to see the latest status.',
                    $receipt,
                    'If you chose Affirm or Klarna, the purchase is only finished once Affirm or Klarna has emailed you to confirm it.',
                ],
                canRetry: false,
            );
        }

        // NOT_PAID: cancelled, expired or declined.
        $why = match (true) {
            $method === 'affirm' && $abandoned
                => 'Your Affirm checkout ended without a loan being confirmed — it was cancelled, timed out, or Affirm did not approve it.',
            $abandoned && $name !== null
                => "Your {$name} payment was cancelled or timed out before it was finished.",
            $abandoned
                => 'The payment was cancelled or timed out before it was finished.',
            default
                => $o['declined'],
        };

        return new self('alert',
            subject: "Order {$ref} was not completed — no payment was taken",
            title: 'Your order was not completed',
            intro: "{$why} No payment was taken, no order has been placed, and nothing will ship.",
            option: $o['label'],
            stepsHeading: 'If you still want the system',
            steps: array_merge(
                ['Use the button below to go back to checkout. Your details and delivery address are saved, so you only need to pay.'],
                $abandoned ? [] : $o['afterDecline'],
                $o['again'] !== null && $o['finish'] !== []
                    ? ["{$o['again']}: ".lcfirst($o['finish'][0])]
                    : [],
                ["Whichever way you pay, {$seller} emails you a payment receipt once the funds are received. "
                    .'Until you have that receipt, your order has not gone through.'],
            ),
            canRetry: true,
        );
    }

    /**
     * Everything option-specific, in one place per payment option.
     *
     *   label        — "Payment option you chose: …"
     *   name         — the short name for sentences
 *   again        — how the retry tip opens ("If you choose Affirm again")
     *   shipsWhen    — the intro's second sentence while it is unfinished
     *   finish       — what a FINISHED payment looks like with this option
     *   terms        — size-dependent terms worth knowing before confirming
     *   expiry       — how long an unfinished attempt lasts
     *   declined     — the one-line reason when it was refused
     *   afterDecline — what to do about a refusal
     *   paid         — what this option adds once the order is placed
     *
     * @return array{label:?string, name:?string, again:?string, shipsWhen:string, finish:list<string>, terms:list<string>, expiry:list<string>, declined:string, afterDecline:list<string>, paid:list<string>}
     */
    private static function option(?string $method, int $total, string $seller): array
    {
        $generic = [
            'label'        => null,
            'name'         => null,
            'again'        => null,
            'shipsWhen'    => "{$seller} ships only once the payment is complete and the funds are received.",
            'finish'       => [],
            'terms'        => [],
            'expiry'       => [],
            'declined'     => 'The payment did not go through.',
            'afterDecline' => [],
            'paid'         => [],
        ];

        return match ($method) {
            'affirm' => [
                'label'     => 'Affirm — pay over time with an Affirm loan',
                'name'      => 'Affirm',
                'again'     => 'If you choose Affirm again',
                'shipsWhen' => "{$seller} ships only once Affirm has approved you, you have confirmed the loan with Affirm, and the funds are received.",
                'finish'    => [
                    'Checking how much you could borrow (prequalifying) is not buying. You must choose a plan and confirm the loan on Affirm\'s screens, then be brought back to our checkout page.',
                    'When it is finished, Affirm emails you to confirm your loan and the purchase appears in your Affirm account. No confirmation from Affirm means no loan, and no order.',
                ],
                // Stripe: from $1,700 up Affirm offers only monthly plans.
                'terms'     => $total >= 170000
                    ? ['For an order of this size Affirm offers monthly plans, and may ask for a down payment. Check the terms before you confirm.']
                    : [],
                'expiry'    => ['If you leave Affirm without confirming, the checkout expires after about 12 hours and nothing is charged.'],
                'declined'  => 'Affirm did not approve this payment.',
                'afterDecline' => ['Affirm makes its own lending decisions and can explain a decision through your Affirm account. You can also choose a different way to pay.'],
                'paid'      => ['Affirm emails you to confirm your loan. Your repayments are made to Affirm under the plan you chose — not to '.$seller.' or to us.'],
            ],

            'klarna' => [
                'label'     => $total > 400000 ? 'Klarna Financing — pay over time with Klarna' : 'Klarna — pay over time with Klarna',
                'name'      => 'Klarna',
                'again'     => 'If you choose Klarna again',
                'shipsWhen' => "{$seller} ships only once Klarna has approved you, you have confirmed the purchase with Klarna, and the funds are received.",
                'finish'    => [
                    'Being approved by Klarna is not the same as buying. You must confirm the purchase on Klarna\'s screens, then be brought back to our checkout page.',
                    'Once your order ships, Klarna emails your payment plan and it appears in the Klarna app.',
                ],
                // Stripe: above $4,000 only Klarna Financing is offered in the US.
                'terms'     => $total > 400000
                    ? ['At this order size Klarna offers only its Financing option, which is subject to approval and not available in every state.']
                    : [],
                'expiry'    => ['If you leave Klarna without confirming, the checkout expires after about 3 hours and nothing is charged.'],
                'declined'  => 'Klarna did not approve this payment.',
                'afterDecline' => ['Klarna makes its own lending decisions and can explain a decision through your Klarna account. You can also choose a different way to pay.'],
                'paid'      => ['Klarna emails your payment plan when your order ships, and it appears in the Klarna app. Your repayments are made to Klarna.'],
            ],

            'us_bank_account' => [
                'label'     => 'Bank payment (ACH) from your checking or savings account',
                'name'      => 'bank',
                'again'     => 'If you pay from your bank account again',
                'shipsWhen' => "{$seller} ships only once the bank payment has cleared and the funds are received.",
                'finish'    => ['Finish connecting your bank account when asked. A bank payment then takes up to 4 business days to clear.'],
                'terms'     => [],
                'expiry'    => [],
                'declined'  => 'Your bank did not accept the payment — for example because of insufficient funds, a closed account or a block on debits.',
                'afterDecline' => ['Check with your bank before trying again, or choose a different way to pay.'],
                'paid'      => [],
            ],

            'cashapp' => [
                'label'     => 'Cash App Pay',
                'name'      => 'Cash App',
                'again'     => 'If you use Cash App again',
                'shipsWhen' => "{$seller} ships only once you have approved the payment in Cash App and the funds are received.",
                'finish'    => ['Approve the payment in Cash App when asked — by scanning the QR code or on your phone — then come back to our checkout page.'],
                'terms'     => ['Cash App sets its own spending limits, which can be lower than this order. If Cash App refuses it, choose a different way to pay.'],
                'expiry'    => ['The request to approve in Cash App lasts about 10 minutes.'],
                'declined'  => 'Cash App did not approve this payment.',
                'afterDecline' => ['Cash App sets its own spending limits, which can be lower than this order. You can choose a different way to pay.'],
                'paid'      => [],
            ],

            'amazon_pay' => [
                'label'     => 'Amazon Pay',
                'name'      => 'Amazon Pay',
                'again'     => 'If you use Amazon Pay again',
                'shipsWhen' => "{$seller} ships only once you have finished paying on Amazon's page and the funds are received.",
                'finish'    => ['Finish paying on Amazon\'s page, then be brought back to our checkout page.'],
                'terms'     => [],
                'expiry'    => [],
                'declined'  => 'Amazon Pay did not approve this payment.',
                'afterDecline' => ['Check the payment method in your Amazon account, or choose a different way to pay.'],
                'paid'      => [],
            ],

            'card', 'link' => [
                'label'     => $method === 'link' ? 'Link — your saved payment details' : 'Card',
                'name'      => $method === 'link' ? 'Link' : 'card',
                'again'     => $method === 'link' ? 'If you use Link again' : 'If you pay by card again',
                'shipsWhen' => "{$seller} ships only once the payment is complete and the funds are received.",
                'finish'    => ['Some banks ask you to confirm the payment in their app or with a code. Complete that step when asked.'],
                'terms'     => [],
                'expiry'    => [],
                'declined'  => 'Your card was declined.',
                'afterDecline' => ['Your bank can tell you why. You can also use a different card or choose a different way to pay.'],
                'paid'      => [],
            ],

            null, '' => $generic,

            default => array_merge($generic, [
                'label' => \App\Models\VendorPaymentAudit::methodName($method),
                'name'  => \App\Models\VendorPaymentAudit::methodName($method),
            ]),
        };
    }
}
