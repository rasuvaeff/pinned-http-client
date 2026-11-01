<?php

declare(strict_types=1);

namespace Rasuvaeff\PinnedHttpClient\Tests;

use GuzzleHttp\Psr7\Request;
use Rasuvaeff\PinnedHttpClient\PinnedClient;
use Rasuvaeff\PinnedHttpClient\RedirectFollower;
use Rasuvaeff\PinnedHttpClient\Refusal;
use Rasuvaeff\PinnedHttpClient\RequestRefused;
use Rasuvaeff\PinnedHttpClient\Tests\Support\LoopbackOnly;
use Rasuvaeff\PinnedHttpClient\Tests\Support\MapResolver;
use Rasuvaeff\PinnedHttpClient\Tests\Support\Server;
use Rasuvaeff\PinnedHttpClient\TooManyRedirects;
use Testo\Assert;
use Testo\Assert\ExpectException;
use Testo\Codecov\Covers;
use Testo\Test;

/**
 * Каждый переход — через закрепляющий клиент заново; `internal.invalid` разрешается в 10.0.0.1, который политика не
 * пускает, `other.invalid` — второй источник на том же сервере.
 */
#[Test]
#[Covers(RedirectFollower::class)]
#[Covers(TooManyRedirects::class)]
final class RedirectFollowerTest
{
    private function follower(int $max = 5): RedirectFollower
    {
        return new RedirectFollower(new PinnedClient(
            policy: new LoopbackOnly(),
            resolver: new MapResolver([
                'pinned.invalid' => ['127.0.0.1'],
                'other.invalid' => ['127.0.0.1'],
                'internal.invalid' => ['10.0.0.1'],
            ]),
            connectTimeoutSeconds: 2.0,
        ), $max);
    }

    /**
     * @return array<string, mixed>
     */
    private function json(string $body): array
    {
        /** @var array<string, mixed> */
        return json_decode($body, associative: true, flags: JSON_THROW_ON_ERROR);
    }

    private function to(int $port, string $target, int $status = 302, string $host = 'pinned.invalid'): string
    {
        return 'http://' . $host . ':' . $port . '/to?' . http_build_query(['url' => $target, 'status' => $status]);
    }

    public function aChainWithinTheLimitIsFollowedToTheEnd(): void
    {
        Server::run(function (int $port): void {
            $response = $this->follower(3)->sendRequest(new Request('GET', 'http://pinned.invalid:' . $port . '/chain/3'));

            Assert::same($response->getStatusCode(), 200);
            Assert::same($this->json((string) $response->getBody())['path'], '/chain/0');
        });
    }

    public function theDefaultLimitIsFiveRedirects(): void
    {
        $follower = new RedirectFollower(new PinnedClient(
            policy: new LoopbackOnly(),
            resolver: new MapResolver(['pinned.invalid' => ['127.0.0.1']]),
            connectTimeoutSeconds: 2.0,
        ));

        Server::run(function (int $port) use ($follower): void {
            $response = $follower->sendRequest(new Request('GET', 'http://pinned.invalid:' . $port . '/chain/5'));

            Assert::same($response->getStatusCode(), 200);

            try {
                $follower->sendRequest(new Request('GET', 'http://pinned.invalid:' . $port . '/chain/6'));
            } catch (TooManyRedirects $tooMany) {
                Assert::same($tooMany->limit, 5);

                return;
            }

            throw new \LogicException('The default redirect limit did not apply');
        });
    }

    public function aLongerChainIsRefused(): void
    {
        Server::run(function (int $port): void {
            try {
                $this->follower(2)->sendRequest(new Request('GET', 'http://pinned.invalid:' . $port . '/chain/3'));
            } catch (TooManyRedirects $tooMany) {
                Assert::same([$tooMany->limit, $tooMany->getMessage()], [2, 'More than 2 redirects']);
                Assert::same($tooMany->getRequest()->getUri()->getPath(), '/chain/1');

                return;
            }

            throw new \LogicException('The redirect limit did not apply');
        });
    }

    public function aZeroLimitRefusesTheFirstRedirect(): void
    {
        Server::run(function (int $port): void {
            try {
                $this->follower(0)->sendRequest(new Request('GET', 'http://pinned.invalid:' . $port . '/redirect'));
            } catch (TooManyRedirects) {
                Assert::true(actual: true);

                return;
            }

            throw new \LogicException('A redirect was followed with a zero limit');
        });
    }

    public function aHopToAnInternalAddressIsRefusedByThePolicy(): void
    {
        Server::run(function (int $port): void {
            try {
                $this->follower()->sendRequest(new Request('GET', $this->to($port, 'http://internal.invalid/admin')));
            } catch (RequestRefused $refused) {
                Assert::same($refused->reason, Refusal::AddressNotAllowed);
                Assert::same((string) $refused->getRequest()->getUri(), 'http://internal.invalid/admin');

                return;
            }

            throw new \LogicException('The internal hop was requested');
        });
    }

    public function aSeeOtherTurnsAPostIntoAGetWithoutBody(): void
    {
        Server::run(function (int $port): void {
            $request = new Request('POST', $this->to($port, '/landing', 303), ['Content-Type' => 'text/plain'], 'payload');
            $answer = $this->json((string) $this->follower()->sendRequest($request)->getBody());

            Assert::same([$answer['method'], $answer['path'], $answer['body']], ['GET', '/landing', '']);
        });
    }

    public function aFoundTurnsAPostIntoAGetButKeepsOtherMethods(): void
    {
        Server::run(function (int $port): void {
            $post = $this->json((string) $this->follower()->sendRequest(new Request('POST', $this->to($port, '/a', 302), [], 'x'))->getBody());
            $put = $this->json((string) $this->follower()->sendRequest(new Request('PUT', $this->to($port, '/b', 302), [], 'x'))->getBody());

            Assert::same([$post['method'], $post['body']], ['GET', '']);
            Assert::same([$put['method'], $put['body']], ['PUT', 'x']);
        });
    }

    public function aTemporaryRedirectKeepsTheMethodAndTheBody(): void
    {
        Server::run(function (int $port): void {
            $answer = $this->json((string) $this->follower()->sendRequest(new Request('POST', $this->to($port, '/c', 307), [], 'kept'))->getBody());

            Assert::same([$answer['method'], $answer['path'], $answer['body']], ['POST', '/c', 'kept']);
        });
    }

    public function credentialsStayOnTheSameOriginAndAreDroppedForAnother(): void
    {
        Server::run(function (int $port): void {
            $headers = ['Authorization' => 'Bearer secret', 'Cookie' => 'sid=1'];

            $same = $this->json((string) $this->follower()->sendRequest(new Request('GET', $this->to($port, '/same'), $headers))->getBody());
            $other = $this->json((string) $this->follower()->sendRequest(
                new Request('GET', $this->to($port, 'http://other.invalid:' . $port . '/other'), $headers),
            )->getBody());

            Assert::same([$same['authorization'], $same['cookie']], ['Bearer secret', 'sid=1']);
            Assert::same([$other['host'], $other['authorization'], $other['cookie']], ['other.invalid:' . $port, null, null]);
        });
    }

    public function aRedirectWithoutLocationOrWithAnUnreadableOneIsTheAnswer(): void
    {
        Server::run(function (int $port): void {
            $empty = $this->follower()->sendRequest(new Request('GET', $this->to($port, '')));
            $broken = $this->follower()->sendRequest(new Request('GET', $this->to($port, 'http://:80')));
            $notRedirect = $this->follower()->sendRequest(new Request('GET', $this->to($port, '/x', 200)));

            Assert::same($empty->getStatusCode(), 302);
            Assert::same($broken->getStatusCode(), 302);
            Assert::same($notRedirect->getStatusCode(), 200);
        });
    }

    #[ExpectException(\InvalidArgumentException::class)]
    public function aNegativeLimitIsRejected(): void
    {
        new RedirectFollower(new PinnedClient(), -1);
    }
}
