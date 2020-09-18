<?php

class VKAPI
{
    private $token;
    const API_HOST = 'https://api.vk.com';
    const API_VERSION = '5.103';

    public function __construct(string $token)
    {
        $this->token = $token;
    }

    public function updateToken(string $token): void
    {
        $this->token = $token;
    }

    public function callMethod(string $method, array $parameters = []): array
    {
        $handler = curl_init();
        $parameters = array_merge(['access_token' => $this->token, 'v' => self::API_VERSION], $parameters);
        curl_setopt($handler, CURLOPT_URL, self::API_HOST . '/method/' . $method);
        curl_setopt($handler, CURLOPT_POST, true);
        curl_setopt($handler, CURLOPT_POSTFIELDS, $parameters);
        curl_setopt($handler, CURLOPT_RETURNTRANSFER, true);
        $curlResult = curl_exec($handler);
        return json_decode($curlResult, true);
    }

    public function sendMessage(string $peerId, string $text, array $extraParameters = []): array
    {
        $randomId = time() . rand(10, 99);
        $parameters = [
            'peer_id' => $peerId,
            'message' => $text,
            'dont_parse_links' => true,
            'random_id' => $randomId
        ];
        if (!empty($extraParameters))
            $parameters = array_merge($parameters, $extraParameters);
        return $this->callMethod('messages.send', $parameters);
    }
}
