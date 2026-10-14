<?php

declare(strict_types=1);

namespace Nabafzar\LogCenter\Tests\Unit\Processors;

use Nabafzar\LogCenter\Contracts\PayloadEnricherInterface;
use Nabafzar\LogCenter\DTO\LogPayload;
use Nabafzar\LogCenter\Processors\PayloadProcessor;
use Nabafzar\LogCenter\Tests\TestCase;

final class PayloadProcessorTest extends TestCase
{
    public function test_it_masks_secrets_in_nested_payloads(): void
    {
        $payload = $this->makePayload([
            'message' => 'password=super-secret token: abc123',
            'trace' => 'token=trace-secret',
            'context' => [
                'password' => 'super-secret',
                'nested' => [
                    'api_key' => 'key-123',
                    'visible' => 'kept',
                ],
            ],
            'extra' => [
                'secret' => 'hidden-value',
            ],
            'request' => [
                'body' => [
                    'token' => 'request-token',
                ],
            ],
            'user' => [
                'access_token' => 'user-token',
            ],
            'labels' => [
                'authorization' => 'header-token',
            ],
        ]);

        $processed = (new PayloadProcessor)->process($payload);

        $this->assertStringContainsString('password=******', $processed->message);
        $this->assertStringContainsString('token: ******', $processed->message);
        $this->assertStringContainsString('token=******', $processed->trace ?? '');
        $this->assertSame('******', $processed->context['password']);
        $this->assertSame('******', $processed->context['nested']['api_key']);
        $this->assertSame('kept', $processed->context['nested']['visible']);
        $this->assertSame('******', $processed->extra['secret']);
        $this->assertSame('******', $processed->request['body']['token']);
        $this->assertSame('******', $processed->user['access_token']);
        $this->assertSame('******', $processed->labels['authorization']);
    }

    public function test_it_masks_array_valued_secret_keys(): void
    {
        $payload = $this->makePayload([
            'context' => [
                'token' => ['real-secret', 'another-secret'],
                'headers' => [
                    'authorization' => ['Bearer real-secret'],
                ],
                'visible' => ['kept'],
            ],
        ]);

        $processed = (new PayloadProcessor)->process($payload);

        $this->assertSame('******', $processed->context['token']);
        $this->assertSame('******', $processed->context['headers']['authorization']);
        $this->assertSame(['kept'], $processed->context['visible']);
    }

    public function test_enrichers_run_before_masking(): void
    {
        $enricher = new class implements PayloadEnricherInterface
        {
            public function enrich(LogPayload $payload): LogPayload
            {
                return new LogPayload(
                    level: $payload->level,
                    message: $payload->message,
                    channel: $payload->channel,
                    datetime: $payload->datetime,
                    context: array_merge($payload->context, [
                        'deploy_sha' => 'abc123',
                        'token' => 'should-be-masked',
                    ]),
                );
            }
        };

        $processor = new PayloadProcessor;
        $processor->addEnricher($enricher);

        $processed = $processor->process($this->makePayload([]));

        $this->assertSame('abc123', $processed->context['deploy_sha']);
        $this->assertSame('******', $processed->context['token']);
    }

    public function test_multiple_enrichers_run_in_order(): void
    {
        $log = [];

        $first = new class($log) implements PayloadEnricherInterface
        {
            public function __construct(private array &$log) {}

            public function enrich(LogPayload $payload): LogPayload
            {
                $this->log[] = 'first';

                return new LogPayload(
                    level: $payload->level,
                    message: $payload->message,
                    channel: $payload->channel,
                    datetime: $payload->datetime,
                    extra: ['step' => 'first'],
                );
            }
        };

        $second = new class($log) implements PayloadEnricherInterface
        {
            public function __construct(private array &$log) {}

            public function enrich(LogPayload $payload): LogPayload
            {
                $this->log[] = 'second';

                return new LogPayload(
                    level: $payload->level,
                    message: $payload->message,
                    channel: $payload->channel,
                    datetime: $payload->datetime,
                    extra: array_merge($payload->extra, ['step' => 'second']),
                );
            }
        };

        $processor = new PayloadProcessor;
        $processor->addEnricher($first);
        $processor->addEnricher($second);

        $processed = $processor->process($this->makePayload([]));

        $this->assertSame(['first', 'second'], $log);
        $this->assertSame('second', $processed->extra['step']);
    }

    public function test_it_replaces_invalid_utf8_so_providers_can_encode_the_payload(): void
    {
        $payload = (new PayloadProcessor)->process($this->makePayload([
            'message' => "broken \xB1 bytes",
            'context' => ['note' => "also \xFF broken"],
        ]));

        $this->assertTrue(mb_check_encoding($payload->message, 'UTF-8'));
        $this->assertTrue(mb_check_encoding($payload->context['note'], 'UTF-8'));
        $this->assertNotFalse(json_encode([$payload->message, $payload->context]));
    }

    public function test_it_masks_keys_that_contain_a_sensitive_key(): void
    {
        $payload = (new PayloadProcessor)->process($this->makePayload([
            'request' => ['body' => [
                'current_password' => 'old-secret',
                'new_password' => 'new-secret',
                '_token' => 'csrf-token',
                'client_secret' => 'client-secret',
                'X-Api-Token' => 'api-token',
                'email' => 'user@example.com',
            ]],
        ]));

        $this->assertSame([
            'current_password' => '******',
            'new_password' => '******',
            '_token' => '******',
            'client_secret' => '******',
            'X-Api-Token' => '******',
            'email' => 'user@example.com',
        ], $payload->request['body']);
    }

    public function test_it_masks_the_whole_secret_value_in_text(): void
    {
        $cases = [
            'Authorization: Bearer eyJhbGciOiJIUzI1NiJ9.abc.def' => 'Authorization: Bearer ******',
            'curl -H "Authorization: Basic dXNlcjpwYXNz"' => 'curl -H "Authorization: Basic ******"',
            "password: 'hunter 2' next" => "password: '******' next",
            '{"password":"a \"quoted\" pass","name":"kept"}' => '{"password":"******","name":"kept"}',
            'password=hunter2&x=1' => 'password=******&x=1',
            'token: abc123, other' => 'token: ******, other',
        ];

        $processor = new PayloadProcessor;

        foreach ($cases as $message => $expected) {
            $this->assertSame($expected, $processor->process($this->makePayload(['message' => $message]))->message);
        }
    }

    public function test_it_normalizes_tags_to_a_list_of_valid_strings(): void
    {
        $payload = (new PayloadProcessor)->process($this->makePayload([
            'tags' => ['payments', ['nested'], null, 42, " broken \xB1 ", 'payments', ''],
        ]));

        $this->assertSame(['payments', '42', 'broken ?'], $payload->tags);
    }
}
