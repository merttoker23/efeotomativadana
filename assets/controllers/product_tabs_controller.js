import HomeTabsController from './home_tabs_controller.js';

export default class extends HomeTabsController {
    onKeydown(event) {
        // Panels are focusable for reading; navigation keys only switch tabs on the tab strip.
        if (this.tabTargets.includes(event.target)) {
            super.onKeydown(event);
        }
    }
}
