<?php

declare(strict_types=1);

namespace Rasuvaeff\PinnedHttpClient\Tests\Internal;

use Rasuvaeff\PinnedHttpClient\Internal\DnsMessage;
use Rasuvaeff\PinnedHttpClient\Internal\InvalidDnsResponse;
use Testo\Assert;
use Testo\Assert\ExpectException;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(DnsMessage::class)]
final class DnsMessageTest
{
    public function encodeQueryBuildsAWellFormedQuestion(): void
    {
        $message = DnsMessage::encodeQuery('example.test', DnsMessage::TYPE_A, 0x1234);

        $expected = "\x12\x34" . "\x01\x00" . "\x00\x01" . "\x00\x00" . "\x00\x00" . "\x00\x00"
            . "\x07example" . "\x04test" . "\x00"
            . "\x00\x01" . "\x00\x01";

        Assert::same($message, $expected);
    }

    #[DataProvider('invalidNamesProvider')]
    #[ExpectException(\InvalidArgumentException::class)]
    public function throwsOnHostNameWithAnEmptyOrOverlongLabel(string $name): void
    {
        DnsMessage::encodeQuery($name, DnsMessage::TYPE_A, 1);
    }

    public static function invalidNamesProvider(): iterable
    {
        yield 'empty label in the middle' => ['example..test'];
        yield 'leading empty label' => ['.example.test'];
        yield 'label longer than 63 bytes' => [str_repeat('a', 64) . '.test'];
    }

    public function aLabelOfSixtyThreeBytesFitsAQuery(): void
    {
        $name = str_repeat('a', 63) . '.test';
        $message = DnsMessage::encodeQuery($name, DnsMessage::TYPE_A, 1);

        Assert::true(str_contains($message, str_repeat('a', 63)));
    }

    public function parsesIpv4AndIpv6AnswersBehindCompressedNames(): void
    {
        $query = DnsMessage::encodeQuery('example.test', DnsMessage::TYPE_A, 0x0102);

        // ответ: заголовок, вопрос (эхо запроса), две записи с сжатым именем-указателем на вопрос
        $answerA = "\xc0\x0c" . "\x00\x01" . "\x00\x01" . "\x00\x00\x00\x3c" . "\x00\x04"
            . inet_pton('93.184.216.34');
        $answerAAAA = "\xc0\x0c" . "\x00\x1c" . "\x00\x01" . "\x00\x00\x00\x3c" . "\x00\x10"
            . inet_pton('2606:2800:220:1:248:1893:25c8:1946');

        $message = "\x01\x02" . "\x81\x80"
            . "\x00\x01" . "\x00\x02" . "\x00\x00" . "\x00\x00" // qdcount=1, ancount=2, ns=0, ar=0
            . substr($query, 12)
            . $answerA . $answerAAAA;

        Assert::same(
            DnsMessage::parseAddresses($message, 0x0102),
            ['93.184.216.34', '2606:2800:220:1:248:1893:25c8:1946'],
        );
    }

    public function collectsTheAddressBehindACnameChain(): void
    {
        $query = DnsMessage::encodeQuery('alias.test', DnsMessage::TYPE_A, 5);

        // answer 1: alias.test CNAME real.test (несжатое имя); answer 2: real.test A 1.2.3.4
        $answerCname = "\x05alias" . "\x04test" . "\x00" . "\x00\x05" . "\x00\x01" . "\x00\x00\x00\x3c" . "\x00\x0b"
            . "\x04real" . "\x04test" . "\x00";
        $answerA = "\x04real" . "\x04test" . "\x00" . "\x00\x01" . "\x00\x01" . "\x00\x00\x00\x3c" . "\x00\x04"
            . inet_pton('1.2.3.4');

        $message = "\x00\x05" . "\x81\x80"
            . "\x00\x01" . "\x00\x02" . "\x00\x00" . "\x00\x00"
            . substr($query, 12)
            . $answerCname . $answerA;

        Assert::same(DnsMessage::parseAddresses($message, 5), ['1.2.3.4']);
    }

    public function rejectsWhenTheIdDoesNotMatch(): void
    {
        $message = substr_replace($this->sampleResponse(), "\xff\xff", 0, 2);

        $this->assertInvalid($message, 0x0102, 'does not match');
    }

    public function rejectsWhenQrIsUnset(): void
    {
        // флаги 0x0180: RD и RA, но не QR
        $message = substr_replace($this->sampleResponse(), "\x01\x80", 2, 2);

        $this->assertInvalid($message, 0x0102, 'not a response');
    }

    public function rejectsWhenRcodeIsNotZero(): void
    {
        // младший байт флагов: RCODE = 3
        $message = substr_replace($this->sampleResponse(), "\x83", 3, 1);

        $this->assertInvalid($message, 0x0102, 'reports an error');
    }

    public function aQrOnlyResponseWithAnswersIsParsed(): void
    {
        // флаги 0x8000: только QR, прочих битов нет — валидный ответ
        $message = substr_replace($this->sampleResponse(), "\x80", 2, 1);

        Assert::same(DnsMessage::parseAddresses($message, 0x0102), ['93.184.216.34']);
    }

    public function aResponseWithBitsBeyondTheQrMaskIsStillRejected(): void
    {
        // флаги 0x8001: QR установлен, RCODE=1: отвергается по RCODE
        $message = substr_replace($this->sampleResponse(), "\x80\x01", 2, 2);

        $this->assertInvalid($message, 0x0102, 'reports an error');
    }

    public function anUnknownHighBitInTheFlagsIsNotAnRcode(): void
    {
        // флаги 0x8010: QR установлен, бит 4 (не RCODE) — ответ валиден
        $message = substr_replace($this->sampleResponse(), "\x80\x10", 2, 2);

        Assert::same(DnsMessage::parseAddresses($message, 0x0102), ['93.184.216.34']);
    }

    public function rejectsTruncatedHeader(): void
    {
        $this->assertInvalid(substr($this->sampleResponse(), 0, 11), 0x0102, 'shorter than');
    }

    public function rejectsAnswerWithoutQuestionTail(): void
    {
        // qdcount=1, но секция вопроса урезана: разбор вопроса не находит конца имени
        // вопрос урезан внутри метки: имя идёт за конец сообщения
        DnsMessage::encodeQuery('example.test', DnsMessage::TYPE_A, 13);
        $message = "\x00\x0d" . "\x81\x80"
            . "\x00\x01" . "\x00\x01" . "\x00\x00" . "\x00\x00"
            . "\x07exampl";

        $this->assertInvalid($message, 13, 'label runs past the end');
    }

    public function ignoresNonAddressRecords(): void
    {
        $query = DnsMessage::encodeQuery('example.test', DnsMessage::TYPE_A, 7);

        // TXT-запись не адрес: пропускается, разбор продолжается
        $answerTxt = "\xc0\x0c" . "\x00\x10" . "\x00\x01" . "\x00\x00\x00\x3c" . "\x00\x05" . "\x04text";
        $answerA = "\xc0\x0c" . "\x00\x01" . "\x00\x01" . "\x00\x00\x00\x3c" . "\x00\x04" . inet_pton('1.2.3.4');
        $message = "\x00\x07" . "\x81\x80"
            . "\x00\x01" . "\x00\x02" . "\x00\x00" . "\x00\x00"
            . substr($query, 12)
            . $answerTxt . $answerA;

        Assert::same(DnsMessage::parseAddresses($message, 7), ['1.2.3.4']);
    }

    public function rejectsATruncatedRdata(): void
    {
        $query = DnsMessage::encodeQuery('example.test', DnsMessage::TYPE_A, 15);
        $answer = "\xc0\x0c" . "\x00\x01" . "\x00\x01" . "\x00\x00\x00\x3c" . "\x00\x04" . "\x01\x02";

        // rdlength обещает 4 байта, а лежат 2 — обрыв в конце сообщения
        $message = "\x00\x0f" . "\x81\x80"
            . "\x00\x01" . "\x00\x01" . "\x00\x00" . "\x00\x00"
            . substr($query, 12)
            . $answer;

        $this->assertInvalid($message, 15, 'record data is truncated');
    }

    public function rejectsATruncatedRecordHeader(): void
    {
        $query = DnsMessage::encodeQuery('example.test', DnsMessage::TYPE_A, 29);
        $answer = "\xc0\x0c" . "\x00\x01" . "\x00\x01"; // только 4 байта из 10

        $message = "\x00\x1d" . "\x81\x80"
            . "\x00\x01" . "\x00\x01" . "\x00\x00" . "\x00\x00"
            . substr($query, 12)
            . $answer;

        $this->assertInvalid($message, 29, 'record header is truncated');
    }

    public function anARecordWithALyingRdlengthIsNotAnAddress(): void
    {
        $query = DnsMessage::encodeQuery('example.test', DnsMessage::TYPE_A, 21);
        // rdlength 2 у A-записи: inet_ntop не вызывается, запись пропускается
        $answer = "\xc0\x0c" . "\x00\x01" . "\x00\x01" . "\x00\x00\x00\x3c" . "\x00\x02" . "\x01\x02";

        $message = "\x00\x15" . "\x81\x80"
            . "\x00\x01" . "\x00\x01" . "\x00\x00" . "\x00\x00"
            . substr($query, 12)
            . $answer;

        Assert::same(DnsMessage::parseAddresses($message, 21), []);
    }

    public function anAaaaRecordWithALyingRdlengthIsNotAnAddress(): void
    {
        $query = DnsMessage::encodeQuery('example.test', DnsMessage::TYPE_AAAA, 23);
        // rdlength 15 у AAAA-записи
        $answer = "\xc0\x0c" . "\x00\x1c" . "\x00\x01" . "\x00\x00\x00\x3c" . "\x00\x0f" . str_repeat("\x20", 15);

        $message = "\x00\x17" . "\x81\x80"
            . "\x00\x01" . "\x00\x01" . "\x00\x00" . "\x00\x00"
            . substr($query, 12)
            . $answer;

        Assert::same(DnsMessage::parseAddresses($message, 23), []);
    }

    public function aPointerCutBeforeItsSecondByteIsRejected(): void
    {
        $query = DnsMessage::encodeQuery('example.test', DnsMessage::TYPE_A, 25);
        // имя записи начинается с указателя, а второй байт указателя за пределами сообщения
        $message = "\x00\x19" . "\x81\x80"
            . "\x00\x01" . "\x00\x01" . "\x00\x00" . "\x00\x00"
            . substr($query, 12)
            . "\xc0";

        $this->assertInvalid($message, 25, 'cut before its second byte');
    }

    public function stopsOnACyclicCompressionPointer(): void
    {
        $query = DnsMessage::encodeQuery('example.test', DnsMessage::TYPE_A, 9);
        $answerStart = strlen($query);

        // имя записи — указатель на сам указатель: цикл, разрывается лимитом переходов
        $loopingName = chr(0xC0 | (($answerStart >> 8) & 0x3F)) . chr($answerStart & 0xFF);
        $answer = $loopingName . "\x00\x01" . "\x00\x01" . "\x00\x00\x00\x3c" . "\x00\x04" . inet_pton('1.2.3.4');
        $message = "\x00\x09" . "\x81\x80"
            . "\x00\x01" . "\x00\x01" . "\x00\x00" . "\x00\x00"
            . substr($query, 12)
            . $answer;

        $this->assertInvalid($message, 9, 'loops');
    }

    public function aChainOfOneHundredLegalPointersStillResolves(): void
    {
        $query = DnsMessage::encodeQuery('example.test', DnsMessage::TYPE_A, 27);
        $answerStart = strlen($query);
        $tail = "\x00\x01" . "\x00\x01" . "\x00\x00\x00\x3c" . "\x00\x04" . inet_pton('1.2.3.4');
        // resume — за первым указателем, на хвосте записи; зона цепочки — после хвоста
        $chainZone = $answerStart + 2 + strlen($tail);
        $head = chr(0xC0 | (($chainZone >> 8) & 0x3F)) . chr($chainZone & 0xFF);

        // #1..#98 — на следующий указатель зоны; #99 — на вопрос
        $zone = '';
        for ($i = 0; $i < 98; $i++) {
            $target = $chainZone + ($i + 1) * 2;
            $zone .= chr(0xC0 | (($target >> 8) & 0x3F)) . chr($target & 0xFF);
        }
        $last = "\xc0\x0c"; // последний из 100 указателей ведёт на имя вопроса

        $message = "\x00\x1b" . "\x81\x80"
            . "\x00\x01" . "\x00\x01" . "\x00\x00" . "\x00\x00"
            . substr($query, 12)
            . $head . $tail . $zone . $last;

        // ровно 100 переходов — внутри лимита: адрес собирается, цикла нет
        Assert::same(DnsMessage::parseAddresses($message, 27), ['1.2.3.4']);
    }

    public function aPointerBeyondByteTwoFiftyFiveStillResolves(): void
    {
        // вопрос из пяти длинных меток длиннее 256 байтов, поэтому указатель на имя записи
        // начинается с 0xC1, а не 0xC0 — это другой сдвиг и другой старший байт
        $query = DnsMessage::encodeQuery(
            str_repeat('a', 63) . '.' . str_repeat('b', 63) . '.' . str_repeat('c', 63)
            . '.' . str_repeat('d', 63) . '.' . str_repeat('e', 63) . '.tt',
            DnsMessage::TYPE_A,
            31,
        );
        $nameStart = strlen($query); // запись начинается сразу за вопросом (без 12 байт заголовка)
        $tail = "\x00\x01" . "\x00\x01" . "\x00\x00\x00\x3c" . "\x00\x04" . inet_pton('9.8.7.6');
        $realName = "\x04real" . "\x04test" . "\x00";
        $target = $nameStart + 2 + strlen($tail); // настоящее имя лежит за хвостом записи
        $pointer = chr(0xC0 | (($target >> 8) & 0x3F)) . chr($target & 0xFF);

        Assert::true($target > 255);

        $message = "\x00\x1f" . "\x81\x80"
            . "\x00\x01" . "\x00\x01" . "\x00\x00" . "\x00\x00"
            . substr($query, 12)
            . $pointer . $tail . $realName;

        Assert::same(DnsMessage::parseAddresses($message, 31), ['9.8.7.6']);
    }

    public function anAnswerHeaderWithoutAnswersIsEnough(): void
    {
        // ровно заголовок, qdcount=0, ancount=0: адресов нет
        Assert::same(DnsMessage::parseAddresses("\x01\x02\x81\x80\x00\x00\x00\x00\x00\x00\x00\x00", 0x0102), []);
    }

    private function assertInvalid(string $message, int $expectedId, string $reason): void
    {
        try {
            DnsMessage::parseAddresses($message, $expectedId);
            Assert::same('InvalidDnsResponse was expected', 'but nothing was thrown');
        } catch (InvalidDnsResponse $invalid) {
            Assert::string($invalid->getMessage())->contains($reason);
        }
    }

    private function sampleResponse(): string
    {
        $query = DnsMessage::encodeQuery('example.test', DnsMessage::TYPE_A, 0x0102);
        $answer = "\xc0\x0c" . "\x00\x01" . "\x00\x01" . "\x00\x00\x00\x3c" . "\x00\x04" . inet_pton('93.184.216.34');

        return "\x01\x02" . "\x81\x80"
            . "\x00\x01" . "\x00\x01" . "\x00\x00" . "\x00\x00"
            . substr($query, 12)
            . $answer;
    }
}
