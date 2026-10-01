<?php
/**
 * API ve Form verileri için genel Validator sınıfı
 */
class Validator
{
    private array $errors = [];
    private array $data;

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public function required(string $field, string $message): self
    {
        $value = $this->getValue($field);
        if ($value === null || trim((string)$value) === '') {
            $this->addError($field, $message);
        }
        return $this;
    }

    public function numeric(string $field, string $message): self
    {
        $value = $this->getValue($field);
        if ($value !== null && trim((string)$value) !== '' && !is_numeric($value)) {
            $this->addError($field, $message);
        }
        return $this;
    }

    public function min(string $field, int $min, string $message): self
    {
        $value = $this->getValue($field);
        if ($value !== null && trim((string)$value) !== '') {
            if (is_numeric($value) && (float)$value < $min) {
                $this->addError($field, $message);
            }
        }
        return $this;
    }

    private function getValue(string $field)
    {
        $keys = explode('.', $field);
        $curr = $this->data;
        foreach ($keys as $key) {
            if (is_array($curr) && array_key_exists($key, $curr)) {
                $curr = $curr[$key];
            } else {
                return null;
            }
        }
        return $curr;
    }

    public function addError(string $field, string $message): void
    {
        if (!isset($this->errors[$field])) {
            $this->errors[$field] = $message;
        }
    }

    public function hasErrors(): bool
    {
        return !empty($this->errors);
    }

    public function getErrors(): array
    {
        return $this->errors;
    }
}
