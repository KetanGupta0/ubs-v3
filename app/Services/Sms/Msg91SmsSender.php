<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * MSG91, chosen for Indian delivery and DLT compliance.
 *
 * Indian regulation requires pre approved templates, so transactional sends go
 * through the template endpoint with variables rather than posting free text,
 * which would be rejected by the operator.
 */
class Msg91SmsSender implements SmsSender
{
    public function __construct(
        protected string $authKey,
        protected ?string $senderId = null,
        protected ?string $defaultTemplateId = null,
    ) {}

    public function send(string $to, string $message, array $context = []): void
    {
        $templateId = $context['template_id'] ?? $this->defaultTemplateId;

        $response = $templateId
            ? $this->sendTemplate($to, $templateId, $context['variables'] ?? [])
            : $this->sendFlow($to, $message);

        if ($response->failed()) {
            Log::error('MSG91 send failed', [
                'to' => $this->mask($to),
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new RuntimeException('Unable to send the SMS right now.');
        }
    }

    protected function sendTemplate(string $to, string $templateId, array $variables)
    {
        return Http::asJson()
            ->withHeaders(['authkey' => $this->authKey])
            ->timeout(15)
            ->retry(2, 200)
            ->post('https://control.msg91.com/api/v5/flow/', [
                'template_id' => $templateId,
                'short_url' => '0',
                'recipients' => [['mobiles' => $this->digits($to), ...$variables]],
            ]);
    }

    protected function sendFlow(string $to, string $message)
    {
        return Http::asJson()
            ->withHeaders(['authkey' => $this->authKey])
            ->timeout(15)
            ->post('https://control.msg91.com/api/v5/message/', [
                'sender' => $this->senderId,
                'route' => config('services.sms.msg91.route', 4),
                'mobiles' => $this->digits($to),
                'message' => $message,
            ]);
    }

    /** MSG91 wants digits with a country code and no plus sign. */
    protected function digits(string $mobile): string
    {
        return ltrim(preg_replace('/\D+/', '', $mobile), '+');
    }

    /** Never write a full number into an error log. */
    protected function mask(string $mobile): string
    {
        return substr($mobile, 0, 3).str_repeat('*', max(strlen($mobile) - 6, 0)).substr($mobile, -3);
    }
}
