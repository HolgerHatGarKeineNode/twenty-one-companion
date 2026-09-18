{{-- The Portal connection, injected into the package settings hub (P2).

     It stands directly below „Konto & Identität": the npub is the canonical identity, and
     the Portal is the service bound to it. The component already existed
     (`livewire/portal/connect.blade.php`) — this file is only the registry entry, so the
     order of the sections stays one config line. --}}
<livewire:portal.connect/>
