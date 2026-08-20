<?php

use NBCSIT\Sso\Support\CertificateSubject;

it('prefers what it was handed over anything configured', function () {
    config(['app.url' => 'https://sso.example.edu.au']);

    $subject = CertificateSubject::fromConfig(['commonName' => 'configured.example.edu.au'], 'asked-for.example.edu.au');

    expect($subject->commonName())->toBe('asked-for.example.edu.au');
});

it('prefers the configured common name over the application host', function () {
    config(['app.url' => 'https://sso.example.edu.au']);

    expect(CertificateSubject::fromConfig(['commonName' => 'configured.example.edu.au'])->commonName())
        ->toBe('configured.example.edu.au');
});

it('falls back to the host of the application URL, which single sign-on needs right anyway', function () {
    config(['app.url' => 'https://sso.example.edu.au/subfolder']);

    expect(CertificateSubject::fromConfig([])->commonName())->toBe('sso.example.edu.au');
});

it('falls back to the application name when the URL has no host', function () {
    config(['app.url' => 'not-a-url', 'app.name' => 'Exam Writer']);

    expect(CertificateSubject::fromConfig([])->commonName())->toBe('Exam Writer');
});

it('has no common name at all when the application has neither, so generation can refuse', function () {
    config(['app.url' => '', 'app.name' => '']);

    expect(CertificateSubject::fromConfig([])->commonName())->toBe('');
});

it('takes the organisation from the application name when none is configured', function () {
    config(['app.name' => 'Exam Writer']);

    expect(CertificateSubject::fromConfig([])->toArray())->toHaveKey('organizationName', 'Exam Writer');
});

it('drops components that are empty rather than sending blanks openssl has to encode', function () {
    config(['app.url' => 'https://sso.example.edu.au', 'app.name' => 'Exam Writer']);

    $components = CertificateSubject::fromConfig([
        'countryName' => 'AU',
        'stateOrProvinceName' => '  ',
        'localityName' => null,
        'emailAddress' => 'ithelpdesk@example.edu.au',
    ])->toArray();

    expect($components)->toHaveKey('countryName', 'AU')
        ->and($components)->toHaveKey('emailAddress', 'ithelpdesk@example.edu.au')
        ->and($components)->not->toHaveKey('stateOrProvinceName')
        ->and($components)->not->toHaveKey('localityName')
        ->and($components)->not->toHaveKey('organizationalUnitName');
});

it('trims what it is given, because a trailing space in a common name is invisible', function () {
    expect(CertificateSubject::fromConfig(['commonName' => "  sso.example.edu.au \n"])->commonName())
        ->toBe('sso.example.edu.au');
});

it('describes itself the way a certificate console prints a subject', function () {
    config(['app.url' => 'https://sso.example.edu.au', 'app.name' => 'Exam Writer']);

    expect(CertificateSubject::fromConfig([
        'countryName' => 'AU',
        'stateOrProvinceName' => 'NSW',
        'localityName' => 'Sydney',
        'organizationalUnitName' => 'IT',
        'emailAddress' => 'ithelpdesk@example.edu.au',
    ])->describe())->toBe(
        'CN=sso.example.edu.au, O=Exam Writer, OU=IT, C=AU, ST=NSW, L=Sydney, E=ithelpdesk@example.edu.au',
    );
});
