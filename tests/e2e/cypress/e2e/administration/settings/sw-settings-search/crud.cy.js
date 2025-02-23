// / <reference types="Cypress" />

import CategoryPageObject from '../../../../support/pages/module/sw-category.page-object';

describe('Search: Test crud operations', () => {
    beforeEach(() => {
        cy.createLanguageFixture()
            .then(() => {
                cy.openInitialPage(`${Cypress.env('admin')}#/sw/settings/search/index`);
                cy.get('.sw-skeleton').should('not.exist');
                cy.get('.sw-loader').should('not.exist');
            });
    });

    it('@base @settings: create search configuration for search behaviour', { tags: ['pa-system-settings'] }, () => {
        // Request we want to wait for later
        cy.intercept({
            url: '/api/search/product-search-config',
            method: 'POST',
        }).as('saveData');

        // Make sure that everything need to load first.
        cy.get('.sw-loader').should('not.exist');

        // Change value of Minimal search term length
        cy.get('.sw-settings-search__search-behaviour-term-length input').clear().type('10');

        // Check for Or Behaviour option
        cy.get('input[type="radio"]#sw-field--searchBehaviourConfigs-andLogic-1').scrollIntoView().check();

        cy.get('.sw-language-switch__select').typeSingleSelectAndCheck(
            'Philippine',
            '.sw-language-switch__select',
        );

        // Verify save search config method
        cy.wait('@saveData').its('response.statusCode').should('equal', 200);
    });

    it('@base @settings: create search configuration for search behaviour bases on another language', { tags: ['pa-system-settings'] }, () => {
        const page = new CategoryPageObject();
        // Request we want to wait for later
        cy.intercept({
            url: '/api/search/product-search-config',
            method: 'POST',
        }).as('saveData');

        // Make sure that everything need to load first.
        cy.get('.sw-loader').should('not.exist');

        // Choose German language
        page.changeTranslation('Deutsch', 0);

        // change value of Minimal search term length
        cy.get('.sw-settings-search__search-behaviour-term-length input').clear().type('19');

        // Check for Or Behaviour option
        cy.get('input[type="radio"]#sw-field--searchBehaviourConfigs-andLogic-1').scrollIntoView().check();

        cy.get('.sw-settings-search__button-save').click();

        // Verify save search config method
        cy.wait('@saveData').its('response.statusCode').should('equal', 200);
    });
});
