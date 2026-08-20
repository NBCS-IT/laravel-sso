<?php

namespace NBCSIT\Sso\Support;

/**
 * One difference between the identity provider as configured and the identity
 * provider as its metadata now describes it.
 *
 * `guarded` is the load-bearing flag. A signing certificate that changes is
 * routine — every IdP rolls its keys, and an unattended refresh exists to keep
 * up with exactly that. A change to the entity ID, the endpoints or the NameID
 * format is different in kind: those say *who* is being trusted and *how a
 * person is identified*, and a metadata document fetched over HTTP is not
 * evidence enough to rewrite them by itself. Those are recorded and wait for an
 * administrator.
 *
 * For a guarded change, `to` is the value itself and not a description of it,
 * because applying the change later means writing exactly that.
 */
final readonly class MetadataChange
{
    public function __construct(
        public string $field,
        public string $label,
        public ?string $from,
        public ?string $to,
        public bool $guarded,
    ) {}

    public static function guarded(string $field, string $label, ?string $from, ?string $to): self
    {
        return new self($field, $label, $from, $to, true);
    }

    public static function routine(string $field, string $label, ?string $from, ?string $to): self
    {
        return new self($field, $label, $from, $to, false);
    }

    /**
     * @return array{field: string, label: string, from: string|null, to: string|null, guarded: bool}
     */
    public function toArray(): array
    {
        return [
            'field' => $this->field,
            'label' => $this->label,
            'from' => $this->from,
            'to' => $this->to,
            'guarded' => $this->guarded,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            (string) ($row['field'] ?? ''),
            (string) ($row['label'] ?? ''),
            isset($row['from']) ? (string) $row['from'] : null,
            isset($row['to']) ? (string) $row['to'] : null,
            (bool) ($row['guarded'] ?? false),
        );
    }

    public function describe(): string
    {
        return match (true) {
            $this->from === null => $this->label.': '.$this->to,
            $this->to === null => $this->label.': '.$this->from.' (removed)',
            default => $this->label.': '.$this->from.' → '.$this->to,
        };
    }
}
