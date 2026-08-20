<x-layouts.app :title="$provider->key . ' metadata'" :heading="$provider->key"
    subheading="Where this identity provider's configuration comes from, and everything that has changed it.">
    <x-slot:actions>
        <x-admin.button variant="secondary" :href="route('admin.settings.saml.edit')">Back to single sign-on</x-admin.button>
    </x-slot:actions>

    <div class="max-w-3xl space-y-6">
        @if ($pending)
            <div class="rounded-lg border border-amber-300 bg-amber-50 p-6">
                <h2 class="font-semibold text-amber-900">Changes waiting for you</h2>
                <p class="mt-1 text-sm text-amber-900">
                    The metadata now says something different about who this provider is or where it lives. A refresh
                    will not write that on its own — a signing certificate rolls on a schedule the provider owns, but an
                    entity ID or an endpoint moving is either a migration somebody planned or a document that did not
                    come from the provider at all, and this application cannot tell those apart.
                </p>

                <dl class="mt-4 space-y-3 text-sm">
                    @foreach ($pending as $change)
                        <div class="rounded-md bg-white/70 p-3">
                            <dt class="font-medium text-slate-900">{{ $change->label }}</dt>
                            <dd class="mt-1 space-y-0.5 font-mono text-xs break-all text-slate-600">
                                <p><span class="text-slate-400">now</span> {{ $change->from ?? '—' }}</p>
                                <p><span class="text-slate-400">new</span> {{ $change->to ?? '—' }}</p>
                            </dd>
                        </div>
                    @endforeach
                </dl>

                <p class="mt-3 text-xs text-amber-900">
                    Found {{ $provider->pending_metadata_at?->diffForHumans() }}. Check them against the provider's own
                    console before applying. Discarding keeps what is configured and stops this being raised again for
                    this version of the document.
                </p>

                <div class="mt-4 flex items-center gap-3">
                    <form method="POST" action="{{ route('admin.settings.saml.metadata.pending.apply', $provider) }}"
                        onsubmit="return confirm('Apply these changes to the identity provider?')">
                        @csrf
                        <x-admin.button>Apply</x-admin.button>
                    </form>

                    <form method="POST" action="{{ route('admin.settings.saml.metadata.pending.discard', $provider) }}"
                        onsubmit="return confirm('Discard these changes and keep the current configuration?')">
                        @csrf
                        @method('DELETE')
                        <x-admin.button variant="secondary">Discard</x-admin.button>
                    </form>
                </div>
            </div>
        @endif

        <div class="rounded-lg border border-slate-200 bg-white p-6">
            <h2 class="font-semibold">In use now</h2>

            <dl class="mt-3 space-y-3 text-sm">
                <div>
                    <dt class="text-slate-500">Entity ID</dt>
                    <dd class="font-mono text-xs break-all">{{ $provider->idp_entity_id }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500">Sign-in URL</dt>
                    <dd class="font-mono text-xs break-all">{{ $provider->idp_login_url }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500">Sign-out URL</dt>
                    <dd class="font-mono text-xs break-all">{{ $provider->idp_logout_url ?: '— none published —' }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500">NameID format</dt>
                    <dd class="font-mono text-xs">{{ $provider->name_id_format }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500">
                        Signing certificates
                        <span class="text-slate-400">({{ count($certificates) }})</span>
                    </dt>
                    <dd class="mt-1 space-y-1">
                        @foreach ($certificates as $certificate)
                            <p
                                class="font-mono text-xs {{ $certificate->hasExpired() ? 'font-semibold text-rose-700' : 'text-slate-700' }}">
                                {{ $certificate->describe() }}{{ $certificate->hasExpired() ? ' — expired' : '' }}
                            </p>
                        @endforeach
                    </dd>
                </div>
            </dl>

            @if (count($certificates) > 1)
                <p class="mt-3 rounded bg-slate-50 px-3 py-2 text-xs text-slate-600">
                    More than one certificate is normal during a key rollover. All of them are offered to the toolkit,
                    so an assertion signed with any of them is accepted.
                </p>
            @endif
        </div>

        <form method="POST" action="{{ route('admin.settings.saml.metadata.update', $provider) }}"
            class="space-y-5 rounded-lg border border-slate-200 bg-white p-6">
            @csrf
            @method('PUT')

            <div>
                <h2 class="font-semibold">Metadata source</h2>
                <p class="mt-1 text-sm text-slate-600">
                    Saving here only records where the document lives; it does not fetch it. Use Refresh below for that.
                </p>
            </div>

            <x-admin.field label="Metadata URL" name="metadata_url"
                hint="Entra ID calls this the “App Federation Metadata Url”; ADFS publishes it at /FederationMetadata/2007-06/FederationMetadata.xml.">
                <x-admin.input name="metadata_url" type="url" :value="$provider->metadata_url" class="font-mono text-xs" />
            </x-admin.field>

            <label class="flex items-start gap-2 text-sm">
                <input type="checkbox" name="auto_refresh" value="1" @checked(old('auto_refresh', $provider->metadata_auto_refresh))
                    class="mt-0.5 rounded border-slate-300 text-teal-600 focus:ring-teal-500" />
                <span>
                    <span class="font-medium">Re-read it daily</span>
                    <span class="block text-xs text-slate-500">
                        New signing certificates are applied straight away. Anything else is held for you above.
                    </span>
                </span>
            </label>

            <x-admin.button>Save source</x-admin.button>
        </form>

        <div class="space-y-4 rounded-lg border border-slate-200 bg-white p-6">
            <div>
                <h2 class="font-semibold">Refresh now</h2>
                <p class="mt-1 text-sm text-slate-600">
                    @if ($provider->metadata_checked_at)
                        Last checked {{ $provider->metadata_checked_at->diffForHumans() }}.
                    @else
                        Never checked.
                    @endif
                    @if ($provider->metadata_synced_at)
                        Last change applied {{ $provider->metadata_synced_at->diffForHumans() }}.
                    @endif
                </p>
            </div>

            @if ($provider->metadata_error)
                <div class="rounded border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-900">
                    <p class="font-semibold">The last check failed</p>
                    <p class="mt-0.5">{{ $provider->metadata_error }}</p>
                </div>
            @endif

            <form method="POST" action="{{ route('admin.settings.saml.metadata.refresh', $provider) }}"
                enctype="multipart/form-data" class="space-y-4">
                @csrf

                @if ($provider->metadata_url)
                    <p class="text-sm text-slate-600">
                        Reads
                        <code
                            class="rounded bg-slate-100 px-1 font-mono text-xs break-all">{{ $provider->metadata_url }}</code>,
                        or the file you choose below instead.
                    </p>
                @else
                    <p class="rounded bg-amber-50 px-3 py-2 text-sm text-amber-900">
                        No metadata URL is saved, so a refresh needs a file.
                    </p>
                @endif

                <x-admin.field label="Metadata file" name="file"
                    hint="Optional. Overrides the URL for this refresh.">
                    <input type="file" name="file" id="file" accept=".xml,application/xml,text/xml"
                        class="block w-full text-sm text-slate-700 file:mr-3 file:rounded-md file:border-0 file:bg-slate-100 file:px-3 file:py-2 file:text-sm file:font-medium file:text-slate-700 hover:file:bg-slate-200" />
                </x-admin.field>

                <x-admin.button>Refresh</x-admin.button>
            </form>
        </div>

        <div class="rounded-lg border border-slate-200 bg-white p-6">
            <h2 class="font-semibold">History</h2>
            <p class="mt-1 text-sm text-slate-600">
                Every time this provider was added, changed, held back or failed to be read. A scheduled check that
                found nothing different is not listed — only the time of the last check, above, moves.
            </p>

            @if ($events->isEmpty())
                <p class="mt-3 text-sm text-slate-500">Nothing yet.</p>
            @else
                <ul class="mt-4 divide-y divide-slate-100">
                    @foreach ($events as $event)
                        <li class="py-3 text-sm">
                            <div class="flex flex-wrap items-center gap-2">
                                <span
                                    class="rounded px-1.5 py-0.5 text-xs font-medium {{ $event->outcome->badgeClasses() }}">
                                    {{ $event->outcome->label() }}
                                </span>
                                <span class="text-xs text-slate-500">
                                    {{ $event->created_at?->format('j M Y, g:ia') }}
                                    &middot; {{ $event->source->label() }}
                                    &middot; {{ $event->actor() }}
                                </span>
                            </div>

                            <p class="mt-1 text-slate-700">{{ $event->message }}</p>

                            @if ($event->change_set)
                                <ul
                                    class="mt-1 list-outside list-disc space-y-0.5 pl-5 text-xs break-all text-slate-600">
                                    @foreach ($event->changeList() as $change)
                                        <li>{{ $change->describe() }}</li>
                                    @endforeach
                                </ul>
                            @endif

                            @if ($event->warnings)
                                <ul class="mt-1 list-outside list-disc space-y-0.5 pl-5 text-xs text-amber-800">
                                    @foreach ($event->warnings as $warning)
                                        <li>{{ $warning }}</li>
                                    @endforeach
                                </ul>
                            @endif
                        </li>
                    @endforeach
                </ul>

                <div class="mt-4">{{ $events->links() }}</div>
            @endif
        </div>
    </div>
</x-layouts.app>
