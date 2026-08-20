<x-layouts.app title="SAML signing certificate" heading="This application's signing certificate"
    subheading="The certificate this application proves itself with, and whether it signs the messages it sends.">
    <x-slot:actions>
        <x-admin.button variant="secondary" :href="route('admin.settings.saml.edit')">Back to single sign-on</x-admin.button>
    </x-slot:actions>

    <div class="max-w-3xl space-y-6">
        @if (!$canSign)
            <div class="rounded-lg border border-amber-300 bg-amber-50 p-6">
                <h2 class="font-semibold text-amber-900">Nothing to sign with</h2>
                <p class="mt-1 text-sm text-amber-900">
                    This application has no usable certificate and private key, so it cannot sign the messages it
                    sends and publishes no signing key in its metadata. That is fine if your identity provider does
                    not ask for signed requests. If it does, generate a certificate below and import this
                    application's metadata at the identity provider.
                </p>
            </div>
        @endif

        @if (($settings->sign_requests || $settings->sign_metadata) && !$canSign)
            <div class="rounded-lg border border-rose-300 bg-rose-50 p-6">
                <h2 class="font-semibold text-rose-900">Signing is switched on, but not in effect</h2>
                <p class="mt-1 text-sm text-rose-900">
                    The settings say to sign, and there is no certificate to sign with. Rather than break every
                    single sign-on route — including this application's metadata document — the setting is being
                    ignored. Generate a certificate to make it take effect.
                </p>
            </div>
        @endif

        <div class="rounded-lg border border-slate-200 p-6">
            <h2 class="font-semibold text-slate-900">In use now</h2>

            @if ($pair->primary)
                <dl class="mt-4 space-y-2 text-sm">
                    <div>
                        <dt class="text-slate-500">Subject</dt>
                        <dd class="font-mono text-xs break-all text-slate-900">{{ $pair->primary->subject ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Thumbprint (SHA-1)</dt>
                        <dd class="font-mono text-xs break-all text-slate-900">{{ $pair->primary->thumbprint ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Valid</dt>
                        <dd
                            @class([
                                'text-slate-900' => !$pair->primary->hasExpired() && !$pair->primary->expiresWithin(30),
                                'font-medium text-amber-700' => $pair->primary->expiresWithin(30),
                                'font-medium text-rose-700' => $pair->primary->hasExpired(),
                            ])>
                            {{ $pair->primary->startsAt?->format('j M Y') ?? '—' }}
                            to
                            {{ $pair->primary->expiresAt?->format('j M Y') ?? '—' }}
                            @if ($pair->primary->hasExpired())
                                — expired
                            @elseif ($pair->primary->expiresWithin(30))
                                — expiring soon
                            @endif
                        </dd>
                    </div>
                </dl>

                <label class="mt-4 block text-sm text-slate-500" for="primary-certificate">
                    Paste this into the identity provider if it asks for the certificate rather than the metadata
                </label>
                <textarea id="primary-certificate" readonly rows="6"
                    class="mt-1 w-full rounded-md border-slate-300 font-mono text-xs">{{ $pair->primary->body }}</textarea>
            @else
                <p class="mt-1 text-sm text-slate-600">No certificate has been generated yet.</p>
            @endif
        </div>

        <div class="rounded-lg border border-slate-200 p-6">
            <h2 class="font-semibold text-slate-900">Rollover certificate</h2>

            @if ($pair->hasSecondary())
                <p class="mt-1 text-sm text-slate-600">
                    Published in this application's metadata alongside the one in use, so the identity provider can
                    be given it before anything starts signing with it. Import the metadata there, then promote.
                </p>

                <dl class="mt-4 space-y-2 text-sm">
                    <div>
                        <dt class="text-slate-500">Thumbprint (SHA-1)</dt>
                        <dd class="font-mono text-xs break-all text-slate-900">{{ $pair->secondary->thumbprint ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Valid</dt>
                        <dd class="text-slate-900">
                            {{ $pair->secondary->startsAt?->format('j M Y') ?? '—' }}
                            to
                            {{ $pair->secondary->expiresAt?->format('j M Y') ?? '—' }}
                        </dd>
                    </div>
                </dl>

                <textarea id="secondary-certificate" readonly rows="6"
                    class="mt-4 w-full rounded-md border-slate-300 font-mono text-xs">{{ $pair->secondary->body }}</textarea>

                <form class="mt-4" method="POST" action="{{ route('admin.settings.saml.certificate.promote') }}"
                    onsubmit="return confirm('Promote the rollover certificate? Do this only once the identity provider has imported it.')">
                    @csrf
                    <x-admin.button>Promote it</x-admin.button>
                </form>
            @else
                <p class="mt-1 text-sm text-slate-600">
                    There is no rollover certificate. Generate one below when the certificate in use is approaching
                    expiry, or when its key needs replacing.
                </p>
            @endif
        </div>

        <div class="rounded-lg border border-slate-200 p-6">
            <h2 class="font-semibold text-slate-900">Where the identity provider reads this from</h2>
            <p class="mt-1 text-sm text-slate-600">
                Give the identity provider this address, or the document it returns, whenever a certificate changes.
            </p>

            @forelse ($providers as $provider)
                <p class="mt-2 font-mono text-xs break-all text-slate-900">
                    {{ $provider->key }} — {{ url('/'.trim(config('saml2.routesPrefix'), '/').'/'.$provider->uuid.'/metadata') }}
                </p>
            @empty
                <p class="mt-2 text-sm text-slate-600">No identity provider has been set up yet.</p>
            @endforelse
        </div>

        <form class="rounded-lg border border-slate-200 p-6" method="POST"
            action="{{ route('admin.settings.saml.certificate.signing.update') }}">
            @csrf
            @method('PUT')

            <h2 class="font-semibold text-slate-900">Signing</h2>
            <p class="mt-1 text-sm text-slate-600">
                Switch these on only once the identity provider has this application's certificate. Until it does,
                signed messages are messages it cannot verify.
            </p>

            <div class="mt-4 space-y-3 text-sm">
                <label class="flex items-start gap-2">
                    <input type="hidden" name="sign_requests" value="0">
                    <input type="checkbox" name="sign_requests" value="1" @checked($settings->sign_requests)
                        @disabled(!$canSign)>
                    <span>
                        <span class="font-medium text-slate-900">Sign the messages this application sends</span>
                        <span class="block text-slate-600">Authentication requests and logout messages alike.</span>
                    </span>
                </label>

                <label class="flex items-start gap-2">
                    <input type="hidden" name="sign_metadata" value="0">
                    <input type="checkbox" name="sign_metadata" value="1" @checked($settings->sign_metadata)
                        @disabled(!$canSign)>
                    <span>
                        <span class="font-medium text-slate-900">Sign the metadata document this application publishes</span>
                        <span class="block text-slate-600">Some identity providers ask for this; most do not.</span>
                    </span>
                </label>
            </div>

            @if (!$canSign)
                <p class="mt-3 text-xs text-amber-700">
                    Both are unavailable until this application has a certificate and private key.
                </p>
            @endif

            <div class="mt-4">
                <x-admin.button :disabled="!$canSign">Save</x-admin.button>
            </div>
        </form>

        <div class="rounded-lg border border-slate-200 p-6">
            <h2 class="font-semibold text-slate-900">Generate</h2>

            <form class="mt-4" method="POST" action="{{ route('admin.settings.saml.certificate.store') }}">
                @csrf
                <input type="hidden" name="slot" value="secondary">
                <p class="text-sm text-slate-600">
                    Generates a new certificate and private key, and leaves the one in use alone. This is the safe
                    one: nothing changes until you promote it.
                </p>
                <div class="mt-3">
                    <x-admin.button>Generate a rollover certificate</x-admin.button>
                </div>
            </form>

            <details class="mt-6 border-t border-slate-200 pt-4">
                <summary class="cursor-pointer text-sm font-medium text-rose-700">
                    Replace the certificate in use now
                </summary>

                <form class="mt-3" method="POST" action="{{ route('admin.settings.saml.certificate.store') }}"
                    onsubmit="return confirm('Replace the certificate in use? Sign-in stops working until the identity provider has the new one.')">
                    @csrf
                    <input type="hidden" name="slot" value="primary">

                    <p class="text-sm text-rose-900">
                        Every signature the identity provider is validating stops being valid the moment this is
                        written, and stays that way until somebody imports the new certificate there. There is no
                        undo. Unless you are setting this application up for the first time, generate a rollover
                        certificate instead.
                    </p>

                    <x-admin.field class="mt-3" label="Type replace to confirm" name="confirm" required>
                        <x-admin.input name="confirm" />
                    </x-admin.field>

                    <div class="mt-3">
                        <x-admin.button variant="danger">Replace it</x-admin.button>
                    </div>
                </form>
            </details>
        </div>
    </div>
</x-layouts.app>
