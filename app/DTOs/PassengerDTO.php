<?php

namespace App\DTOs;

class PassengerDTO
{
    public function __construct(
        public readonly string $firstName,
        public readonly string $lastName,
        public readonly string $type,
        public readonly string $documentNumber,
    ) {}

    public function toArray(): array
    {
        return [
            'first_name'      => $this->firstName,
            'last_name'       => $this->lastName,
            'type'            => $this->type,
            'document_number' => $this->documentNumber,
        ];
    }
}
