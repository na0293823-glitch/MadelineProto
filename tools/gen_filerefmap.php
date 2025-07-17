<?php declare(strict_types=1);

use danog\MadelineProto\Settings\TLSchema;
use danog\MadelineProto\TL\TL;
use danog\MadelineProto\TL\TLInterface;
use Webmozart\Assert\Assert;

require 'vendor/autoload.php';

$TL = new TL(null);
$TL->init(new TLSchema);

$locations = [];

final class TLContext
{
    public function __construct(
        public readonly TLInterface $tl,
        public readonly string $position,
    ) {
    }

    /**
     * @param Op[] $params
     */
    public function validateParams(string $constructor, bool $isCons, array $params): void
    {
        if ($isCons) {
            $data = $this->tl->getConstructors()->findByPredicate($constructor);
        } else {
            $data = $this->tl->getMethods()->findByMethod($constructor);
        }
        Assert::notFalse($data, "Constructor or method not found for $constructor");
        foreach ($data['params'] as $param) {
            if (!isset($params[$param['name']])) {
                if (isset($param['pow'])) {
                    continue;
                }
                throw new AssertionError("Mandatory parameter {$param['name']} not found in constructor or method $constructor");
            }
            if (isset($param['subtype'])) {
                $t = "Vector<{$param['subtype']}>";
            } else {
                $t = $param['type'];
            }
            $gotT = $params[$param['name']]->getType($this);
            if ($t !== $gotT) {
                throw new AssertionError("Parameter {$param['name']} in constructor or method $constructor has type $t but got $gotT");
            }
            unset($params[$param['name']]);
        }
        if ($params) {
            $extra = implode(', ', array_keys($params));
            throw new AssertionError("Extra parameters in constructor or method $constructor: $extra");
        }
    }

    public function getTypeAtPosition(SimpleExtractorOp $_path): string
    {
        if ($_path instanceof ExtractFromHereOp) {
            Assert::eq($this->position, $_path->path[0], "Current constructor {$this->position} does not match expected constructor {$_path->path[0]}");
        }
        $path = $_path->path;
        $idx = 0;
        $type = null;
        $hadFlag = false;
        do {
            if ($type !== null
                && !isset(self::getConstructorsOfType($this->tl, $type, true, true)[$path[$idx]])
                && !isset(self::getConstructorsOfType($this->tl, $type, false, true)[$path[$idx]])
            ) {
                throw new AssertionError("{$path[$idx]} is a constructor of type $type, path: " . json_encode($path));
            }
            $constructor = $this->tl->getConstructors()->findByPredicate($path[$idx]);
            if ($constructor === false) {
                $constructor = $this->tl->getMethods()->findByMethod($path[$idx]);
            }
            Assert::notFalse($constructor, "Constructor or method not found for path: " . json_encode($path));

            $idx++;
            $type = null;
            $n = $constructor['predicate'] ?? $constructor['method'];
            foreach ($constructor['params'] as $param) {
                if ($param['name'] === $path[$idx]) {
                    Assert::false(isset($param['subtype']), "Got flag for parameter {$path[$idx]} in constructor or method $n");
                    $hadFlag = $hadFlag || isset($param['pow']);
                    $type = $param['type'];
                    break;
                }
            }
            Assert::notNull($type, "Parameter {$path[$idx]} not found in constructor or method $n: " . json_encode($path));
        } while (++$idx < count($path));
        if ($_path->isFlag != $hadFlag) {
            $hadFlag = $hadFlag ? 'flag' : 'no flag';
            $expectedFlag = $_path->isFlag ? 'flag' : 'no flag';
            throw new AssertionError("Expected $expectedFlag; got $hadFlag at " . json_encode($path));
        }

        return $type;
    }
    public static function getConstructorsOfType(TLInterface $tl, string $type, bool $methods, bool $ignoreEmpty = false): array
    {
        $constructors = [];
        if ($methods) {
            foreach ($tl->getMethods()->by_id as $method) {
                if ($method['type'] === $type) {
                    $constructors[$method['method']] = $method;
                }
            }
        } else {
            foreach ($tl->getConstructors()->by_id as $constructor) {
                if ($constructor['type'] === $type) {
                    $constructors[$constructor['predicate']] = $constructor;
                }
            }
        }
        $methods = $methods ? 'methods' : 'constructors';
        if (!$ignoreEmpty) {
            Assert::notEmpty($constructors, "No {$methods} found for type: $type");
        }
        return $constructors;
    }
}

interface Op
{
    public function build(TLContext $tl): array;

    public function getType(TLContext $tl): string;

    public function hasBackreference(): bool;

    public function normalize(array $stack): ?Op;
}

interface SimpleExtractorOp extends Op
{
}

interface ExtractorOrLiteralOp extends SimpleExtractorOp
{
}

interface ActionOp extends Op
{
}

final class Noop implements ActionOp
{
    public function getType(TLContext $tl): string
    {
        return '';
    }

    public function hasBackreference(): bool
    {
        return false;
    }

    public function normalize(array $stack): ?Op
    {
        return $this;
    }

    public function build(TLContext $tl): array
    {
        return ['op' => 'noop'];
    }
}

final class CopyMethodCallOp implements ActionOp
{
    public function __construct(private readonly string $method)
    {
    }
    public function hasBackreference(): bool
    {
        return false;
    }

    public function normalize(array $stack): ?Op
    {
        return $this;
    }
    public function getType(TLContext $tl): string
    {
        return $tl->tl->getMethods()->findByMethod($this->method)['type'];
    }

    public function build(TLContext $tl): array
    {
        Assert::eq($tl->position, $this->method, "Current constructor {$tl->position} does not match expected method {$this->method}");
        $this->getType($tl); // Validate type
        return ['op' => 'copyMethodCall', 'method' => $this->method];
    }
}

final class ThemeFormatOp implements ExtractorOrLiteralOp
{
    public function __construct()
    {
    }

    public function hasBackreference(): bool
    {
        return false;
    }
    public function normalize(array $stack): ?Op
    {
        return $this;
    }
    public function getType(TLContext $tl): string
    {
        return 'string';
    }

    public function build(TLContext $tl): array
    {
        return [
            'op' => 'themeFormat',
        ];
    }
}

final class ExtractFromHereOp implements SimpleExtractorOp
{
    public function __construct(
        /** @var string[] */
        public readonly array $path,
        public readonly bool $isFlag = false,
        public readonly ?Op $ifEmptyFlag = null,
    ) {
        if ($ifEmptyFlag !== null) {
            Assert::true($isFlag);
        }
    }

    public function hasBackreference(): bool
    {
        if ($this->ifEmptyFlag?->hasBackreference()) {
            return true;
        }
        return false;
    }

    public function normalize(array $stack): ?Op
    {
        $if = $this->ifEmptyFlag?->normalize($stack);
        if ($if === null && $this->ifEmptyFlag !== null) {
            return null;
        }
        return new self(
            [...$stack, ...$this->path],
            $this->isFlag,
            $if
        );
    }

    public function getType(TLContext $tl): string
    {
        $t = $tl->getTypeAtPosition($this);
        if ($this->ifEmptyFlag !== null) {
            Assert::eq($this->ifEmptyFlag->getType($tl), $t);
        }
        return $t;
    }

    public function extend(string ...$path): self
    {
        return new self(...$this->path, ...$path);
    }

    public function build(TLContext $tl): array
    {
        // Validate
        $this->getType($tl);
        return [
            'op' => 'extractFromHere',
            'isFlag' => $this->isFlag,
            'ifFlagEmptyUse' => $this->ifEmptyFlag?->build($tl),
            'path' => $this->path,
        ];
    }
}

final class ExtractFromMethodCallOp implements SimpleExtractorOp
{
    public function __construct(
        /** @var string[] */
        public readonly array $path,
        public readonly bool $isFlag = false,
        public readonly ?Op $ifEmptyFlag = null,
    ) {
        if ($ifEmptyFlag !== null) {
            Assert::true($isFlag);
        }
    }

    public function hasBackreference(): bool
    {
        return true;
    }

    public function normalize(array $stack): ?Op
    {
        if ($stack[0] !== $this->path[0]) {
            return null;
        }
        $if = $this->ifEmptyFlag?->normalize($stack);
        if ($if === null && $this->ifEmptyFlag !== null) {
            return null;
        }
        return new ExtractFromHereOp(
            $this->path,
            $this->isFlag,
            $if
        );
    }

    public function getType(TLContext $tl): string
    {
        $t = $tl->getTypeAtPosition($this);
        if ($this->ifEmptyFlag !== null) {
            Assert::eq($this->ifEmptyFlag->getType($tl), $t);
        }
        return $t;
    }

    public function extend(string ...$path): self
    {
        return new self(...$this->path, ...$path);
    }

    public function build(TLContext $tl): array
    {
        // Validate
        $this->getType($tl);
        return [
            'op' => 'extractFromMethodCall',
            'isFlag' => $this->isFlag,
            'ifFlagEmptyUse' => $this->ifEmptyFlag?->build($tl),
            'path' => $this->path,
        ];
    }
}

final class ExtractStickerSetFromDocumentAttributesOp implements SimpleExtractorOp
{
    public function __construct(
        private readonly SimpleExtractorOp $path,
    )
    {
    }

    public function hasBackreference(): bool
    {
        return $this->path->hasBackreference();
    }
    
    public function normalize(array $stack): ?Op
    {
        $path = $this->path->normalize($stack);
        if ($path === null) {
            return null;
        }
        if ($path !== $this->path) {
            return new self($path);
        }
        return $this;
    }
    
    public function getType(TLContext $tl): string
    {
        return 'InputStickerSet';
    }

    public function build(TLContext $tl): array
    {
        Assert::eq($this->path->getType($tl), 'Vector<DocumentAttribute>');
        return [
            'op' => 'extractStickerSetFromDocumentAttributes',
            'from' => $this->path->build($tl),
        ];
    }
}

final class GetInputPeerOp implements ExtractorOrLiteralOp
{
    public function __construct(private readonly SimpleExtractorOp $path)
    {
    }

    public function hasBackreference(): bool
    {
        return $this->path->hasBackreference();
    }
    public function normalize(array $stack): ?Op
    {
        $path = $this->path->normalize($stack);
        if ($path === null) {
            return null;
        }
        if ($path !== $this->path) {
            return new self($path);
        }
        return $this;
    }
    public function getType(TLContext $tl): string
    {
        return 'InputPeer';
    }

    public function build(TLContext $tl): array
    {
        $type = $this->path->getType($tl);
        if ($type === 'InputPeer') {
            return $this->path->build($tl);
        }
        Assert::eq($type, 'Peer', "Expected type 'Peer' at position {$this->path->path[0]} but got '$type'");
        return [
            'op' => 'getInputPeer',
            'from' => $this->path->build($tl),
        ];
    }
}
final class GetInputUserOp implements ExtractorOrLiteralOp
{
    public function __construct(private readonly SimpleExtractorOp $path)
    {
    }

    public function normalize(array $stack): ?Op
    {
        $path = $this->path->normalize($stack);
        if ($path === null) {
            return null;
        }
        if ($path !== $this->path) {
            return new self($path);
        }
        return $this;
    }
    public function hasBackreference(): bool
    {
        return $this->path->hasBackreference();
    }
    public function getType(TLContext $tl): string
    {
        return 'InputUser';
    }

    public function build(TLContext $tl): array
    {
        $type = $this->path->getType($tl);
        if ($type === 'InputUser') {
            return $this->path->build($tl);
        }
        if ($type === 'long') {
            return [
                'op' => 'getInputUserById',
                'from' => $this->path->build($tl),
            ];
        }
        Assert::eq($type, 'User', "Expected type 'User' at position {$this->path->path[0]} but got '$type'");
        return [
            'op' => 'getInputUser',
            'from' => $this->path->build($tl),
        ];
    }
}
final class GetInputChannelOp implements ExtractorOrLiteralOp
{
    public function __construct(private readonly SimpleExtractorOp $path)
    {
    }

    public function normalize(array $stack): ?Op
    {
        $path = $this->path->normalize($stack);
        if ($path === null) {
            return null;
        }
        if ($path !== $this->path) {
            return new self($path);
        }
        return $this;
    }
    public function hasBackreference(): bool
    {
        return $this->path->hasBackreference();
    }
    public function getType(TLContext $tl): string
    {
        return 'InputChannel';
    }

    public function build(TLContext $tl): array
    {
        $type = $this->path->getType($tl);
        if ($type === 'InputChannel') {
            return $this->path->build($tl);
        }
        if ($type === 'long') {
            return [
                'op' => 'getInputChannelById',
                'from' => $this->path->build($tl),
            ];
        }
        Assert::eq($type, 'Channel', "Expected type 'Channel' at position {$this->path->path[0]} but got '$type'");
        return [
            'op' => 'getInputChannel',
            'from' => $this->path->build($tl),
        ];
    }
}

final class ArrayOp implements ExtractorOrLiteralOp
{
    /** @var Op[] */
    private readonly array $values;
    public function __construct(Op ...$values)
    {
        $this->values = $values;
    }
    public function hasBackreference(): bool
    {
        foreach ($this->values as $value) {
            if ($value->hasBackreference()) {
                return true;
            }
        }
        return false;
    }
    public function normalize(array $stack): ?Op
    {
        $final = [];
        $isDifferent = false;   
        foreach ($this->values as $value) {
            $normalized = $value->normalize($stack);
            if ($normalized === null) {
                return null;
            }
            if ($normalized !== $value) {
                $isDifferent = true;
            }   
            $final[] = $normalized;
        }
        if ($isDifferent) {
            return new self(...$final);
        }
        return $this;
    }

    public function getType(TLContext $tl): string
    {
        return 'Vector<' . $this->values[0]->getType($tl) . '>';
    }

    public function build(TLContext $tl): array
    {
        $arr = [];
        foreach ($this->values as $key => $value) {
            $arr[$key] = $value->build($tl);
        }
        return [
            'op' => 'array',
            'value' => $arr,
        ];
    }
}

final class LiteralOp implements ExtractorOrLiteralOp
{
    public function __construct(private readonly string $type, private readonly mixed $value)
    {
        Assert::inArray($type, ['int', 'long', 'string', 'bool', 'float', '#'], "Invalid type '$type' for LiteralOp");
    }

    public function hasBackreference(): bool
    {
        return false;
    }
    public function normalize(array $stack): ?Op
    {
        return $this;
    }
    public function getType(TLContext $tl): string
    {
        return $this->type;
    }

    public function build(TLContext $tl): array
    {
        return [
            'op' => 'literal',
            'type' => $this->type,
            'value' => $this->value,
        ];
    }
}

final class GetMessageOp implements ExtractorOrLiteralOp
{
    public function __construct(
        private readonly Op $peer,
        private readonly Op $id,
    ) {
    }
    public function normalize(array $stack): ?Op
    {
        $peer = $this->peer->normalize($stack);
        if ($peer === null) {
            return null;
        }
        $id = $this->id->normalize($stack);
        if ($id === null) {
            return null;
        }
        if ($peer !== $this->peer || $id !== $this->id) {
            return new self($peer, $id);
        }
        return $this;
    }
    public function hasBackreference(): bool
    {
        return $this->peer->hasBackreference() || $this->id->hasBackreference();
    }
    public function getType(TLContext $tl): string
    {
        return 'messages.Messages';
    }

    public function build(TLContext $tl): array
    {
        Assert::eq($this->peer->getType($tl), 'Peer');
        Assert::eq($this->id->getType($tl), 'int');
        return [
            'op' => 'get_message',
            'peer' => $this->peer->build($tl),
            'id' => $this->id->build($tl),
        ];
    }
}

final class CallOp implements ActionOp
{
    /** @param Op[] $args */
    public function __construct(
        private readonly string $method,
        private readonly array $args
    ) {
    }
    public function hasBackreference(): bool
    {
        foreach ($this->args as $arg) {
            if ($arg->hasBackreference()) {
                return true;
            }
        }
        return false;
    }
    public function normalize(array $stack): ?Op
    {
        $final = [];
        $isDifferent = false;
        foreach ($this->args as $from => $to) {
            $normalized = $to->normalize($stack);
            if ($normalized === null) {
                return null;
            }
            if ($normalized !== $to) {
                $isDifferent = true;
            }
            $final[$from] = $normalized;
        }
        if ($isDifferent) {
            return new self($this->method, $final);
        }
        return $this;
    }
    public function getType(TLContext $tl): string
    {
        return $tl->tl->getMethods()->findByMethod($this->method)['type'];
    }

    public static function simple(string $method, string $constructor, array $args): self
    {
        $final = [];
        foreach ($args as $from => $to) {
            if (!$to instanceof Op) {
                $to = new ExtractFromHereOp([$constructor, $to]);
            }
            $final[$from] = $to;
        }
        return new CallOp($method, $final);
    }

    public function build(TLContext $tl): array
    {
        $final = [];
        $tl->validateParams($this->method, false, $this->args);
        foreach ($this->args as $from => $to) {
            $final[$from] = $to->build($tl);
        }
        return [
            'op' => 'call',
            'method' => $this->method,
            'args' => $final,
        ];
    }
}
final class ConstructorOp implements ExtractorOrLiteralOp
{
    /** @param Op[] $args */
    public function __construct(
        private readonly string $constructor,
        private readonly array $args
    ) {
    }

    public function normalize(array $stack): ?Op
    {
        $final = [];
        $isDifferent = false;
        foreach ($this->args as $from => $to) {
            $normalized = $to->normalize($stack);
            if ($normalized === null) {
                return null;
            }
            if ($normalized !== $to) {
                $isDifferent = true;
            }
            $final[$from] = $normalized;
        }
        if ($isDifferent) {
            return new self($this->constructor, $final);
        }
        return $this;
    }
    public function hasBackreference(): bool
    {
        foreach ($this->args as $arg) {
            if ($arg->hasBackreference()) {
                return true;
            }
        }
        return false;
    }

    public function getType(TLContext $tl): string
    {
        return $tl->tl->getConstructors()->findByPredicate($this->constructor)['type'];
    }

    public function build(TLContext $tl): array
    {
        $final = [];
        $tl->validateParams($this->constructor, true, $this->args);
        foreach ($this->args as $from => $to) {
            $final[$from] = $to->build($tl);
        }
        return [
            'op' => 'constructor',
            'constructor' => $this->constructor,
            'args' => $final,
        ];
    }
}

foreach (TLContext::getConstructorsOfType($TL, 'Message', false) as $constructor => $_) {
    if ($constructor === 'messageEmpty') {
        continue;
    }
    $locations[$constructor][] = new GetMessageOp(
        new ExtractFromHereOp([$constructor, 'peer_id']),
        new ExtractFromHereOp([$constructor, 'id']),
    );
}
$locations['webPage'][] = CallOp::simple('messages.getWebPage', 'webPage', ['url' => 'url', 'hash' => new LiteralOp('int', 0)]);
$locations['botApp'][] = CallOp::simple('messages.getBotApp', 'botApp', [
    'app' => new ConstructorOp(
        'inputBotAppID',
        [
            'id' => new ExtractFromHereOp(['botApp', 'id']),
            'access_hash' => new ExtractFromHereOp(['botApp', 'access_hash']),
        ]
    ),
    'hash' => new LiteralOp('long', 0),
]);
$locations['botInfo'][] = new CallOp(
    'users.getFullUser',
    ['id' => new GetInputUserOp(new ExtractFromHereOp(['botInfo', 'user_id'], true))],
);
$locations['storyItem'][] = new CallOp('stories.getStoriesByID', [
    'id' => new ArrayOp(new ExtractFromHereOp(['storyItem', 'id'])),
    'peer' => new GetInputPeerOp(new ExtractFromHereOp(['storyItem', 'from_id'], true)),
]);
$locations['messages.getSponsoredMessages'][] = new CopyMethodCallOp('messages.getSponsoredMessages');
$locations['channelAdminLogEvent'][] = new CallOp(
    'channels.getAdminLog',
    [
        'channel' => new GetInputChannelOp(new ExtractFromMethodCallOp(['channels.getAdminLog', 'channel'])),
        'max_id' => new ExtractFromHereOp(['channelAdminLogEvent', 'id']),
        'min_id' => new ExtractFromHereOp(['channelAdminLogEvent', 'id']),
        'limit' => new LiteralOp('int', 1),
        'q' => new LiteralOp('string', ''),
        'flags' => new LiteralOp('#', 0),
    ]
);
$locations['bots.getPreviewInfo'][] = new CopyMethodCallOp('bots.getPreviewInfo');
$locations['updateMessageExtendedMedia'][] = new CallOp(
    'messages.getExtendedMedia',
    [
        'id' => new ArrayOp(new ExtractFromHereOp(['updateMessageExtendedMedia', 'msg_id'])),
        'peer' => new GetInputPeerOp(new ExtractFromHereOp(['updateMessageExtendedMedia', 'peer'])),
    ]
);
$locations['userFull'][] = new CallOp(
    'users.getFullUser',
    [
        'id' => new GetInputUserOp(new ExtractFromHereOp(['userFull', 'id'])),
    ]
);
$locations['chatFull'][] = new CallOp(
    'messages.getFullChat',
    [
        'chat_id' => new ExtractFromHereOp(['chatFull', 'id']),
    ]
);
$locations['channelFull'][] = new CallOp(
    'channels.getFullChannel',
    [
        'channel' => new GetInputChannelOp(new ExtractFromHereOp(['channelFull', 'id'])),
    ]
);
$locations['help.getPremiumPromo'][] = new CopyMethodCallOp('help.getPremiumPromo');
foreach (TLContext::getConstructorsOfType($TL, 'StarsTransaction', false) as $method => $_) {
    $locations[$constructor][] = new CallOp(
        'payments.getStarsTransactionByID',
        [
            'peer' => new ExtractFromMethodCallOp([$constructor, 'peer']),
            'id' => new ConstructorOp(
                'inputStarsTransaction',
                [
                    'id' => new ExtractFromHereOp([$constructor, 'id']),
                    'refund' => new ExtractFromHereOp([$constructor, 'refund']),
                ]
            ),
        ]
    );
}
$locations['attachMenuBot'][] = new CallOp(
    'messages.getAttachMenuBot',
    ['bot' => new GetInputUserOp(new ExtractFromHereOp(['attachMenuBot', 'bot_id']))]
);
$locations['theme'][] = new CallOp(
    'account.getTheme',
    [
        'theme' => new ConstructorOp(
            'inputTheme',
            [
                'id' => new ExtractFromHereOp(['theme', 'id']),
                'access_hash' => new ExtractFromHereOp(['theme', 'access_hash']),
            ]
        ),
        'format' => new ThemeFormatOp(),
    ]
);
$locations['wallPaper'][] = new CallOp(
    'account.getWallPaper',
    [
        'wallpaper' => new ConstructorOp(
            'inputWallPaper',
            [
                'id' => new ExtractFromHereOp(['wallPaper', 'id']),
                'access_hash' => new ExtractFromHereOp(['wallPaper', 'access_hash']),
            ]
        ),
    ]
);

// Multiple variations to handle references from covers in StickerSetCovered and messages.StickerSet
$locations['stickerSet'][] = new CallOp(
    'messages.getStickerSet',
    [
        'stickerset' => new ConstructorOp(
            'inputStickerSetID',
            [
                'id' => new ExtractFromHereOp(['stickerSet', 'id']),
                'access_hash' => new ExtractFromHereOp(['stickerSet', 'access_hash']),
            ],
        ),
        'hash' => new LiteralOp('int', 0),
    ]
);
foreach (['stickerSetMultiCovered', 'stickerSetFullCovered'] as $c) {
    $locations[$c][] = new CallOp(
        'messages.getStickerSet',
        [
            'stickerset' => new ConstructorOp(
                'inputStickerSetID',
                [
                    'id' => new ExtractFromHereOp([$c, 'set', 'stickerSet', 'id']),
                    'access_hash' => new ExtractFromHereOp([$c, 'set', 'stickerSet', 'access_hash']),
                ],
            ),
            'hash' => new LiteralOp('int', 0),
        ]
    );
}
$locations['messages.stickerSet'][] = new CallOp(
    'messages.getStickerSet',
    [
        'stickerset' => new ConstructorOp(
            'inputStickerSetID',
            [
                'id' => new ExtractFromHereOp(['messages.stickerSet', 'set', 'stickerSet', 'id']),
                'access_hash' => new ExtractFromHereOp(['messages.stickerSet', 'set', 'stickerSet', 'access_hash']),
            ],
        ),
        'hash' => new LiteralOp('int', 0),
    ]
);
$locations['messages.savedGifs'][] = new CallOp('messages.getSavedGifs', ['hash' => new LiteralOp('long', 0)]);
foreach (['account.savedRingtones', 'account.savedRingtoneConverted', 'account.uploadRingtone'] as $c) {
    $locations[$c][] = new CallOp('account.getSavedRingtones', ['hash' => new LiteralOp('long', 0)]);
}
$locations['recentMeUrlChatInvite'][] = new CallOp(
    'messages.checkChatInvite',
    ['hash' => new ExtractFromHereOp(['recentMeUrlChatInvite', 'url'])],
);
$locations['messages.availableEffects'][] = new CallOp(
    'messages.getAvailableEffects',
    ['hash' => new LiteralOp('int', 0)],
);
$locations['messages.availableReactions'][] = new CallOp(
    'messages.getAvailableReactions',
    ['hash' => new LiteralOp('int', 0)],
);

$locations['photo'][] = new CallOp(
    'photos.getUserPhotos',
    [
        'user_id' => new ExtractFromMethodCallOp(['photos.getUserPhotos', 'user_id']),
        'offset' => new LiteralOp('int', -1),
        'max_id' => new ExtractFromHereOp(['photo', 'id']),
        'limit' => new LiteralOp('int', 1),
    ]
);
foreach (['photos.updateProfilePhoto', 'photos.updateProfilePhoto'] as $method) {
    $locations['photo'][] = new CallOp(
        'photos.getUserPhotos',
        [
            'user_id' => new ExtractFromMethodCallOp(
                [$method, 'bot'],
                true,
                new ConstructorOp(
                    'inputUserSelf',
                    []
                )
            ),
            'offset' => new LiteralOp('int', -1),
            'max_id' => new ExtractFromHereOp(['photo', 'id']),
            'limit' => new LiteralOp('int', 1),
        ]
    );
}

$locations['document'][] = new CallOp(
    'messages.getStickerSet',
    [
        'stickerset' => new ExtractStickerSetFromDocumentAttributesOp(new ExtractFromHereOp(['document', 'attributes'])),
        'hash' => new LiteralOp('int', 0),
    ]
);
$locations['messages.getDocumentByHash'][] = new CopyMethodCallOp('messages.getDocumentByHash');

// Ignore these for now
foreach (['payments.ResaleStarGifts', 'payments.StarGiftUpgradePreview', 'StarGift'] as $type) {
    foreach (TLContext::getConstructorsOfType($TL, $type, false) as $constructor => $_) {
        $locations[$constructor][] = new Noop();
    }
}

$recurse = static function (Closure $earlyReturn, Closure $onStackEnd, string $type, array $stack = []) use ($TL, &$recurse): void {
    if ($earlyReturn($type)) {
        return;
    }
    $pos = count($stack);
    $found = false;
    foreach ([...$TL->getConstructors()->by_id, ...$TL->getMethods()->by_id] as $constructor) {
        $name = $constructor['predicate'] ?? $constructor['method'];
        foreach ($constructor['params'] as $param) {
            if ($param['type'] === $type && !in_array($name, $stack, true)) {
                $stack[$pos] = $name;
                $recurse($earlyReturn, $onStackEnd, $constructor['type'], $stack);
                $found = true;
            }
            if (isset($param['subtype'])
                && $param['subtype'] === $type
                && !in_array($name, $stack, true)
            ) {
                $stack[$pos] = $name;
                $recurse($earlyReturn, $onStackEnd, $constructor['type'], $stack);
                $found = true;
            }
        }
    }
    unset($stack[$pos]);
    if (!$found
        || $type === 'Update'
        || $type === 'Updates'
        || TLContext::getConstructorsOfType($TL, $type, true, true)
    ) {
        if (
            (
                in_array($stack[0], ['photo', 'document'], true)
                && ($stack[1] ?? null) === 'game'
                && in_array(end($stack), [
                    'messages.webPagePreview',
                    'payments.starsStatus',
                    'messages.invitedUsers',
                    'payments.paymentResult',
                ], true)
            ) || array_intersect(
                [
                    'updateServiceNotification',
                    'updateShortSentMessage',
                    'updateShortMessage',
                    'updateShortChatMessage',
                ],
                $stack,
            ) || end($stack) === 'messages.webPagePreview'
            || end($stack) === 'help.appUpdate'
        ) {
            return;
        }
        $onStackEnd($stack);
    }
};

$fileRefs = ['Document' => 'document', 'Photo' => 'photo'];

$locationTypes = [];

foreach ($locations as $constructor => $ops) {
    foreach ($ops as $op) {
        if ($op->hasBackreference()) {
            continue 2;
        }
    }
    $t = $TL->getConstructors()->findByPredicate($constructor)['type'] ?? $TL->getMethods()->findByMethod($constructor)['type'];
    if (isset($locationTypes[$t])) {
        continue;
    }

    $hasAllConstructors = true;
    foreach (TLContext::getConstructorsOfType($TL, $t, false, true) as $constructor => $data) {
        if (!isset($locations[$constructor]) && $data['params']) {
            $hasAllConstructors = false;
            break;
        }
    }
    if ($hasAllConstructors) {
        $locationTypes[$t] = true;
        continue;
    }
    $methods = TLContext::getConstructorsOfType($TL, $t, true, true);
    $hasAllMethods = (bool) $methods;
    foreach ($methods as $constructor => $_) {
        if (!isset($locations[$constructor])) {
            $hasAllMethods = false;
            break;
        }
    }
    $locationTypes[$t] = $hasAllMethods;
}
$locationTypes = array_filter($locationTypes);

$normalizedLocations = [];

foreach ($fileRefs as $type => $constructor) {
    $recurse(
        static fn (string $type) => false,//isset($locationTypes[$type]),
        static function (array $stack) use ($locations): void {
            $stack = array_reverse($stack);
            $had = false;
            foreach ($stack as $constructor) {
                if (isset($locations[$constructor])) {
                    foreach ($locations[$constructor] as $op) {
                        $normalized = $op->normalize($stack);
                        if ($normalized === null) {
                            continue;
                        }
                        $had = true;
                        $normalizedLocations[$constructor] = $normalized;
                    }
                }
            }
            if (!$had) {
                throw new AssertionError("Uncovered path: " . json_encode($stack));
            }
        },
        $type,
        [$constructor]
    );
}
die;

foreach ($locations as $constructor => $ops) {
    var_dump("Processing $constructor");
    foreach ($ops as $op) {
        var_dump([$constructor, $op->build(new TLContext($TL, $constructor))]);
    }
}
