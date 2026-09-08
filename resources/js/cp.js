/**
 * Control Panel entry. The registered names must match what the controllers
 * pass to `Inertia::render()`, exactly.
 */

import RoomsIndex from './pages/Rooms/Index.vue';
import RoomsShow from './pages/Rooms/Show.vue';
import SetupRequired from './pages/SetupRequired.vue';

Statamic.booting(() => {
    Statamic.$inertia.register('statamic-clientrooms::Rooms/Index', RoomsIndex);
    Statamic.$inertia.register('statamic-clientrooms::Rooms/Show', RoomsShow);
    Statamic.$inertia.register('statamic-clientrooms::SetupRequired', SetupRequired);
});
