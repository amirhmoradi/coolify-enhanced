<div>
    <x-slot:title>
        {{ data_get_str($storage, 'name')->limit(10) }} >Storages | Coolify
    </x-slot>
    <livewire:storage.form :storage="$storage" />
    @if(config('coolify-enhanced.enabled'))
        <div class="pt-6">
            @livewire('enhanced::storage-encryption-form', ['storageId' => $storage->id])
        </div>
    @endif
</div>
