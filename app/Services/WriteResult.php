<?php

namespace App\Services;

/**
 * Typisiertes Ergebnis eines schreibenden Portal-Aufrufs (Gegenstück zu
 * den Lese-DTOs). Statt Exceptions durchzureichen, kapselt es Erfolg und
 * die unterscheidbaren Fehlerklassen in einem Wert, den die Livewire-
 * Komponenten direkt auswerten können (Feldfehler an die Form mappen,
 * Toast/Haptik je nach Status).
 *
 * Über die Factory-Methoden konstruieren, nicht direkt.
 */
final class WriteResult
{
    /**
     * @param  array<int|string, mixed>  $data  Geparster Response-Body bei Erfolg.
     * @param  array<string, list<string>>  $errors  Feld → Fehlermeldungen (nur bei 422).
     */
    private function __construct(
        public readonly WriteStatus $status,
        public readonly array $data = [],
        public readonly array $errors = [],
        public readonly ?string $message = null,
    ) {}

    /**
     * @param  array<int|string, mixed>  $data
     */
    public static function success(array $data = []): self
    {
        return new self(WriteStatus::Success, data: $data);
    }

    /**
     * @param  array<string, list<string>>  $errors
     */
    public static function validationErrors(array $errors, ?string $message = null): self
    {
        return new self(WriteStatus::ValidationError, errors: $errors, message: $message);
    }

    public static function unauthorized(?string $message = null): self
    {
        return new self(WriteStatus::Unauthorized, message: $message);
    }

    public static function forbidden(?string $message = null): self
    {
        return new self(WriteStatus::Forbidden, message: $message);
    }

    public static function networkFailure(?string $message = null): self
    {
        return new self(WriteStatus::NetworkFailure, message: $message);
    }

    public function successful(): bool
    {
        return $this->status === WriteStatus::Success;
    }

    /**
     * ID des angelegten Datensatzes aus der (data-gewrappten) Erfolgs-Antwort.
     * Kapselt die Portal-Response-Shape, damit die Editoren nicht selbst in
     * `data.data.id` greifen müssen — null, wenn nichts Passendes vorhanden ist.
     */
    public function createdId(): ?int
    {
        $id = $this->data['data']['id'] ?? null;

        return is_int($id) ? $id : null;
    }

    public function failed(): bool
    {
        return ! $this->successful();
    }

    public function hasValidationErrors(): bool
    {
        return $this->status === WriteStatus::ValidationError;
    }

    /**
     * Erste Fehlermeldung für ein Feld (oder null), praktisch zum Mappen
     * auf einzelne Form-Inputs.
     */
    public function errorFor(string $field): ?string
    {
        return $this->errors[$field][0] ?? null;
    }
}
