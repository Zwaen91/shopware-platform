import { shallowMount } from '@vue/test-utils';
import swSettingsListingDefaultSalesChannel from 'src/module/sw-settings-listing/component/sw-settings-listing-default-sales-channel';

import 'src/app/component/form/select/entity/sw-entity-multi-select';
import 'src/app/component/form/select/entity/sw-entity-multi-id-select';
import 'src/app/component/form/sw-switch-field';
import 'src/app/component/form/sw-checkbox-field';
import 'src/app/component/form/select/base/sw-select-base';
import 'src/app/component/form/select/base/sw-select-selection-list';
import 'src/app/component/form/select/base/sw-select-result-list';
import 'src/app/component/form/select/base/sw-select-result';

Shopware.Component.register('sw-settings-listing-default-sales-channel', swSettingsListingDefaultSalesChannel);

describe('src/module/sw-settings-listing/component/sw-settings-listing-default-sales-channel', () => {
    let defaultSalesChannelData = {};

    function createEntityCollection(entities = []) {
        return new Shopware.Data.EntityCollection('sales_channel', 'sales_channel', {}, null, entities);
    }

    async function createWrapper() {
        return shallowMount(await Shopware.Component.build('sw-settings-listing-default-sales-channel'), {
            provide: {
                repositoryFactory: {
                    create: () => ({
                        search: () => {
                            return Promise.resolve(createEntityCollection([
                                {
                                    name: 'Storefront',
                                    translated: { name: 'Storefront' },
                                    id: 'STORE-FRONT-MOCK-ID'
                                },
                                {
                                    name: 'Headless',
                                    translated: { name: 'Headless' },
                                    id: 'HEADLESS-MOCK-ID'
                                }
                            ]));
                        }
                    })
                },
                systemConfigApiService: {
                    getValues: () => Promise.resolve(defaultSalesChannelData)
                }
            },
            stubs: {
                'sw-base-field': true,
                'sw-block-field': true,
                'sw-entity-multi-id-select': await Shopware.Component.build('sw-entity-multi-id-select'),
                'sw-entity-multi-select': await Shopware.Component.build('sw-entity-multi-select'),
                'sw-field-error': true,
                'sw-highlight-text': true,
                'sw-icon': true,
                'sw-label': true,
                'sw-loader': true,
                'sw-modal': true,
                'sw-popover': true,
                'sw-select-base': await Shopware.Component.build('sw-select-base'),
                'sw-select-result': await Shopware.Component.build('sw-select-result'),
                'sw-select-result-list': await Shopware.Component.build('sw-select-result-list'),
                'sw-select-selection-list': await Shopware.Component.build('sw-select-selection-list'),
                'sw-settings-listing-visibility-detail': true,
                'sw-switch-field': await Shopware.Component.build('sw-switch-field')
            }
        });
    }

    let wrapper;
    const selectors = {
        componentScope: '.sw-settings-listing-default-sales-channel',
        quickLink: '.sw-settings-listing-default-sales-channel__quick-link',
        activeSwitch: '.sw-settings-listing-default-sales-channel__active-switch input',
        visibilityModal: '.sw-settings-listing-default-sales-channel__visibility-modal',
        selectSelection: '.sw-select__selection',
        resultListItems: '.sw-select-result-list__item-list'
    };

    beforeEach(async () => {
        wrapper = await createWrapper();
    });

    it('should be a Vue.JS component', async () => {
        expect(wrapper.vm).toBeTruthy();
    });

    it('should render data correctly at Default Sales Channel card when there is no default sales channel data', async () => {
        const setVisibilityButton = wrapper.find(selectors.quickLink);
        const activeSwitch = wrapper.find(selectors.activeSwitch);

        expect(activeSwitch.element.checked).toBe(true);
        expect(setVisibilityButton.exists()).toBeFalsy();
    });

    it('should render data correctly at Default Sales Channel card when there is default sales channel data', async () => {
        defaultSalesChannelData = {
            'core.defaultSalesChannel.active': false,
            'core.defaultSalesChannel.salesChannel': [{
                id: '98432def39fc4624b33213a56b8c944d',
                name: 'Headless'
            }],
            'core.defaultSalesChannel.visibility': { '98432def39fc4624b33213a56b8c944d': 10 }
        };

        wrapper = await createWrapper();
        await wrapper.vm.$nextTick();

        const visibilityModalButton = wrapper.find(selectors.quickLink);
        const activeSwitch = wrapper.find(selectors.activeSwitch);

        expect(activeSwitch.element.checked).toBe(false);
        expect(visibilityModalButton.exists()).toBeTruthy();
    });

    it('should display "Set visibility for selected Sales Channels" button when a sales channel is selected', async () => {
        const salesChannelCard = wrapper.find(selectors.componentScope);

        await salesChannelCard.find(selectors.selectSelection).trigger('click');

        await salesChannelCard.find('input').trigger('change');
        await wrapper.vm.$nextTick();

        const list = wrapper.find(selectors.resultListItems).findAll('li');

        await list.at(0).trigger('click');
        await wrapper.vm.$nextTick();

        expect(salesChannelCard.find(selectors.quickLink).exists()).toBeTruthy();
    });

    it('should display modal when clicking "Set visibility for selected Sales Channels" button', async () => {
        const salesChannelCard = wrapper.find(selectors.componentScope);

        await salesChannelCard.find(selectors.selectSelection).trigger('click');

        await salesChannelCard.find('input').trigger('change');
        await wrapper.vm.$nextTick();

        const list = wrapper.find(selectors.resultListItems).findAll('li');

        await list.at(0).trigger('click');
        await wrapper.vm.$nextTick();

        await salesChannelCard.find(selectors.quickLink).trigger('click');
        await wrapper.vm.$nextTick();

        expect(wrapper.find(selectors.visibilityModal).exists()).toBeTruthy();
    });
});
