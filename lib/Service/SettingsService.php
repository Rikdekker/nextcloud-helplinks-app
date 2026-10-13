<?php

namespace OCA\HelpLinks\Service;

use OCP\IAppConfig;

class SettingsService {
    private $appConfig;
    private const APP_ID = 'helplinks';

    public function __construct(IAppConfig $appConfig) {
        $this->appConfig = $appConfig;
    }

    private function getValue(string $key): ?string {
        if (!$this->appConfig->hasKey(self::APP_ID, $key)) {
            return null;
        }
        return $this->appConfig->getValueString(self::APP_ID, $key);
    }

    private function setValue(string $key, ?string $value): void {
        if (empty($value)) {
            $this->appConfig->deleteKey(self::APP_ID, $key);
        } else {
            $this->appConfig->setValueString(self::APP_ID, $key, $value);
        }
    }

    public function getSupportEmail(): ?string {
        return $this->getValue('support_email');
    }

    public function setSupportEmail(?string $email): void {
        $this->setValue('support_email', $email);
    }

    public function getSupportUrl(): ?string {
        return $this->getValue('support_url');
    }

    public function setSupportUrl(?string $url): void {
        $this->setValue('support_url', $url);
    }

    public function getEnvironmentName(): ?string {
        return $this->getValue('environment_name');
    }

    public function setEnvironmentName(?string $name): void {
        $this->setValue('environment_name', $name);
    }

    public function getAll(): array {
        return [
            'supportEmail' => $this->getSupportEmail(),
            'supportUrl' => $this->getSupportUrl(),
            'environmentName' => $this->getEnvironmentName(),
        ];
    }
}
