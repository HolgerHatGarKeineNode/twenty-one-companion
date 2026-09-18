{{-- "Meine Inhalte" on the „Ich" page — app-only, because creating and editing Portal
     content needs a Portal token and the package knows nothing about one.

     Reached through `config('group.ich')` with a `view:` prefix; the row itself uses the
     package's shared geometry (`x-group::ich-row`), so a host-injected row cannot look
     different from a package one. --}}
<x-group::ich-row :href="route('ich.inhalte')" icon="square-2-stack"
                  :label="__('Meine Inhalte')" :hint="__('Meetups, Termine, Orte und Kurse')" />
