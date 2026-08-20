<x-layouts.app title="Single sign-on" heading="Single sign-on">
    {{-- Guarded, because an application that publishes these stubs into a
         routing table it wrote earlier may not have named the certificate
         routes. An unnamed route here would take this whole page down. --}}
    @if (Route::has('admin.settings.saml.certificate.show'))
        <x-slot:actions>
            <x-admin.button variant="secondary" :href="route('admin.settings.saml.certificate.show')">
                This application's signing certificate
            </x-admin.button>
        </x-slot:actions>
    @endif

    <div class="max-w-3xl space-y-6">
        <div class="rounded-lg border border-slate-200 bg-white p-6">
            <h2 class="font-semibold">Identity providers</h2>
            <p class="mt-1 text-sm text-slate-600">
                This site's metadata is published at
                <code class="rounded bg-slate-100 px-1">/saml2/&lbrace;uuid&rbrace;/metadata</code>
                for each provider below. Give that URL to whoever configures the enterprise application.
            </p>

            @if ($tenants->isEmpty())
                <p class="mt-3 rounded bg-amber-50 px-3 py-2 text-sm text-amber-900">
                    No identity provider has been added, so single sign-on cannot be switched on yet. Sign-in falls back
                    to the local password form.
                </p>
            @else
                <ul class="mt-3 divide-y divide-slate-100 text-sm">
                    @foreach ($tenants as $tenant)
                        <li class="flex flex-wrap items-center justify-between gap-2 py-3">
                            <div class="min-w-0">
                                <p class="font-medium">
                                    {{ $tenant->key }}
                                    @if ($settings->default_uuid === $tenant->uuid)
                                        <span
                                            class="ml-1 rounded bg-emerald-100 px-1.5 py-0.5 text-xs font-medium text-emerald-800">
                                            In use
                                        </span>
                                    @endif
                                    @unless ($tenant->enabled)
                                        <span
                                            class="ml-1 rounded bg-slate-200 px-1.5 py-0.5 text-xs font-medium text-slate-700">
                                            Switched off
                                        </span>
                                    @endunless
                                    @if ($tenant->hasPendingChanges())
                                        <span
                                            class="ml-1 rounded bg-amber-100 px-1.5 py-0.5 text-xs font-medium text-amber-900">
                                            Changes to review
                                        </span>
                                    @endif
                                </p>
                                <p class="truncate text-xs text-slate-500">{{ $tenant->idp_entity_id }}</p>
                                <p class="mt-0.5 font-mono text-xs text-slate-400">
                                    {{ url('/saml2/' . $tenant->uuid . '/metadata') }}
                                </p>
                                <p class="mt-1 text-xs text-slate-500">
                                    @if ($tenant->metadata_url)
                                        {{ $tenant->metadata_auto_refresh ? 'Refreshed daily from its metadata URL' : 'Metadata URL saved, automatic refresh off' }}
                                        @if ($tenant->metadata_checked_at)
                                            &middot; checked {{ $tenant->metadata_checked_at->diffForHumans() }}
                                        @endif
                                    @else
                                        No metadata URL, so nothing is refreshed on its own
                                    @endif
                                    @if ($tenant->metadata_error)
                                        <span class="font-medium text-rose-700">&middot; last check failed</span>
                                    @endif
                                </p>
                            </div>
                            <div class="flex items-center gap-4">
                                <a href="{{ route('admin.settings.saml.metadata.show', $tenant) }}"
                                    class="text-sm font-medium text-teal-700 hover:underline">
                                    Metadata &amp; history
                                </a>
                                <form method="POST" action="{{ route('admin.settings.saml.tenant.toggle', $tenant) }}">
                                    @csrf
                                    @method('PUT')
                                    <button type="submit" class="text-sm font-medium text-slate-700 hover:underline">
                                        {{ $tenant->enabled ? 'Switch off' : 'Switch on' }}
                                    </button>
                                </form>
                                <form method="POST" action="{{ route('admin.settings.saml.tenant.destroy', $tenant) }}"
                                    onsubmit="return confirm('Remove this identity provider?')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="text-sm text-rose-700 hover:underline">Remove</button>
                                </form>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif

            <details class="mt-4 rounded-md border border-slate-200 p-4" @if ($tenants->isEmpty()) open @endif>
                <summary class="cursor-pointer text-sm font-medium">Add a provider from its metadata</summary>

                <p class="mt-2 text-sm text-slate-600">
                    Every identity provider publishes its entity ID, endpoints and signing certificates as one XML
                    document. Give this application that document — as a file, or as the URL it lives at — rather than
                    copying five fields across by hand.
                </p>

                <form method="POST" action="{{ route('admin.settings.saml.metadata.store') }}"
                    enctype="multipart/form-data" class="mt-4 space-y-4">
                    @csrf

                    <x-admin.field label="Name" name="provider_name" required
                        hint="Just a label, e.g. “Microsoft Entra ID”.">
                        <x-admin.input name="provider_name" required />
                    </x-admin.field>

                    <x-admin.field label="Metadata file" name="file"
                        hint="The provider's federation metadata XML. Leave empty if you are giving a URL below.">
                        <input type="file" name="file" id="file" accept=".xml,application/xml,text/xml"
                            class="block w-full text-sm text-slate-700 file:mr-3 file:rounded-md file:border-0 file:bg-slate-100 file:px-3 file:py-2 file:text-sm file:font-medium file:text-slate-700 hover:file:bg-slate-200" />
                    </x-admin.field>

                    <x-admin.field label="Metadata URL" name="metadata_url"
                        hint="Stored against the provider so it can be re-read later. Entra ID calls this the “App Federation Metadata Url”.">
                        <x-admin.input name="metadata_url" type="url" class="font-mono text-xs" />
                    </x-admin.field>

                    <x-admin.field label="Entity ID" name="entity_id"
                        hint="Only needed if the document describes more than one provider. Leave empty for the usual single-provider file.">
                        <x-admin.input name="entity_id" class="font-mono text-xs" />
                    </x-admin.field>

                    <label class="flex items-start gap-2 text-sm">
                        <input type="checkbox" name="auto_refresh" value="1" checked
                            class="mt-0.5 rounded border-slate-300 text-teal-600 focus:ring-teal-500" />
                        <span>
                            <span class="font-medium">Re-read the metadata URL daily</span>
                            <span class="block text-xs text-slate-500">
                                A new signing certificate is applied on its own — that is what keeps sign-in working
                                through a key rollover. A change to the entity ID or the endpoints is held for you to
                                approve. Needs a metadata URL.
                            </span>
                        </span>
                    </label>

                    <x-admin.button>Read metadata</x-admin.button>
                </form>
            </details>

            <details class="mt-3 rounded-md border border-slate-200 p-4">
                <summary class="cursor-pointer text-sm font-medium">Add an identity provider by hand</summary>

                <form method="POST" action="{{ route('admin.settings.saml.tenant.store') }}" class="mt-4 space-y-4">
                    @csrf

                    <x-admin.field label="Name" name="name" required
                        hint="Just a label, e.g. “Microsoft Entra ID”.">
                        <x-admin.input name="name" required />
                    </x-admin.field>

                    <x-admin.field label="IdP entity ID" name="idp_entity_id" required>
                        <x-admin.input name="idp_entity_id" required />
                    </x-admin.field>

                    <x-admin.field label="IdP sign-in URL" name="idp_login_url" required>
                        <x-admin.input name="idp_login_url" type="url" required />
                    </x-admin.field>

                    <x-admin.field label="IdP sign-out URL" name="idp_logout_url">
                        <x-admin.input name="idp_logout_url" type="url" />
                    </x-admin.field>

                    <x-admin.field label="IdP signing certificate" name="idp_x509_cert" required
                        hint="Paste the certificate with or without its BEGIN/END lines.">
                        <textarea id="idp_x509_cert" name="idp_x509_cert" rows="5" required
                            class="block w-full rounded-md border-slate-300 font-mono text-xs shadow-sm focus:border-teal-500 focus:ring-teal-500">{{ old('idp_x509_cert') }}</textarea>
                    </x-admin.field>

                    <x-admin.field label="NameID format" name="name_id_format" hint="Leave empty for the default.">
                        <x-admin.input name="name_id_format" />
                    </x-admin.field>

                    <x-admin.button>Add provider</x-admin.button>
                </form>
            </details>
        </div>

        <form method="POST" action="{{ route('admin.settings.saml.update') }}" class="space-y-6"
            x-data="{
                rows: {{ json_encode(
                    collect($settings->group_role_map)->map(fn($role, $group) => ['group' => $group, 'role' => $role])->values()->push(['group' => '', 'role' => ''])->all(),
                ) }},
            }">
            @csrf
            @method('PUT')

            <div class="space-y-5 rounded-lg border border-slate-200 bg-white p-6">
                <h2 class="font-semibold">Behaviour</h2>

                <label class="flex items-start gap-2 text-sm">
                    <input type="checkbox" name="enabled" value="1" @checked(old('enabled', $settings->enabled))
                        class="mt-0.5 rounded border-slate-300 text-teal-600 focus:ring-teal-500" />
                    <span>
                        <span class="font-medium">Use single sign-on</span>
                        <span class="block text-xs text-slate-500">
                            With this off, or with no provider selected below, /login shows the local password form.
                        </span>
                    </span>
                </label>

                <x-admin.field label="Provider to use" name="default_uuid">
                    <select id="default_uuid" name="default_uuid"
                        class="block w-full rounded-md border-slate-300 shadow-sm focus:border-teal-500 focus:ring-teal-500 sm:text-sm">
                        <option value="">None &mdash; use local sign-in</option>
                        @foreach ($tenants as $tenant)
                            <option value="{{ $tenant->uuid }}" @selected(old('default_uuid', $settings->default_uuid) === $tenant->uuid)>
                                {{ $tenant->key }}
                            </option>
                        @endforeach
                    </select>
                </x-admin.field>

                <label class="flex items-start gap-2 text-sm">
                    <input type="checkbox" name="provision_users" value="1" @checked(old('provision_users', $settings->provision_users))
                        class="mt-0.5 rounded border-slate-300 text-teal-600 focus:ring-teal-500" />
                    <span>
                        <span class="font-medium">Create accounts on first sign-in</span>
                        <span class="block text-xs text-slate-500">
                            With this off, only people who already have an account here can sign in.
                        </span>
                    </span>
                </label>

                <label class="flex items-start gap-2 text-sm">
                    <input type="checkbox" name="sync_groups" value="1" @checked(old('sync_groups', $settings->sync_groups))
                        class="mt-0.5 rounded border-slate-300 text-teal-600 focus:ring-teal-500" />
                    <span>
                        <span class="font-medium">Apply group mappings on every sign-in</span>
                        <span class="block text-xs text-slate-500">
                            Only roles named in the mapping table are added or removed; anything granted by hand is left
                            alone.
                        </span>
                    </span>
                </label>
            </div>

            <div class="space-y-5 rounded-lg border border-slate-200 bg-white p-6">
                <h2 class="font-semibold">Attribute mapping</h2>
                <p class="text-sm text-slate-600">Which claim in the assertion holds each piece of information.</p>

                <x-admin.field label="Email attribute" name="email_attribute" required>
                    <x-admin.input name="email_attribute" :value="$settings->email_attribute" required class="font-mono text-xs" />
                </x-admin.field>

                <x-admin.field label="Display name attribute" name="name_attribute" required>
                    <x-admin.input name="name_attribute" :value="$settings->name_attribute" required class="font-mono text-xs" />
                </x-admin.field>

                <x-admin.field label="Groups claim" name="groups_claim" required>
                    <x-admin.input name="groups_claim" :value="$settings->groups_claim" required class="font-mono text-xs" />
                </x-admin.field>
            </div>

            <div class="space-y-4 rounded-lg border border-slate-200 bg-white p-6">
                <div class="flex items-end justify-between gap-3">
                    <div>
                        <h2 class="font-semibold">Group to role mapping</h2>
                        <p class="text-sm text-slate-600">
                            A group value from the assertion on the left, the role it grants on the right. Unlisted
                            groups are ignored.
                        </p>
                    </div>
                    <x-admin.button type="button" variant="secondary"
                        x-on:click="rows.push({ group: '', role: '' })">
                        Add a row
                    </x-admin.button>
                </div>

                <div class="space-y-2">
                    <template x-for="(row, index) in rows" :key="index">
                        <div class="grid grid-cols-1 gap-2 sm:grid-cols-11">
                            <input type="text" placeholder="IdP group value or object ID"
                                :name="`group_map[${index}][group]`" x-model="row.group"
                                class="rounded-md border-slate-300 font-mono text-xs shadow-sm focus:border-teal-500 focus:ring-teal-500 sm:col-span-5" />
                            <select :name="`group_map[${index}][role]`" x-model="row.role"
                                class="rounded-md border-slate-300 text-sm shadow-sm focus:border-teal-500 focus:ring-teal-500 sm:col-span-5">
                                <option value="">&mdash; no role &mdash;</option>
                                @foreach ($roles as $role)
                                    <option value="{{ $role->name }}">{{ $role->name }}</option>
                                @endforeach
                            </select>
                            <button type="button" x-on:click="rows.splice(index, 1)"
                                class="text-left text-sm text-rose-700 hover:underline sm:col-span-1">
                                Remove
                            </button>
                        </div>
                    </template>
                </div>
            </div>

            {{-- ------------------------------------------------------------
                The organisation and contact fields that used to sit here are
                service provider metadata, published in this site's own SAML
                metadata document and read by config/saml2.php. They belong to
                your application's settings class, not to nbcsit/laravel-sso,
                so the fields are left out rather than bound to a class that
                does not have them. Add them back against your own class —
                see the matching block in SamlSettingController::update().
            ------------------------------------------------------------ --}}

            <x-admin.button>Save settings</x-admin.button>
        </form>
    </div>
</x-layouts.app>
