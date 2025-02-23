// / <reference types="Cypress" />

describe('Sales Channel: Visual tests', () => {
    beforeEach(() => {
        const now = new Date(2018, 1, 1);
        cy.clock(now, ['Date'])
            .then(() => {
                cy.openInitialPage(Cypress.env('admin'));
                cy.get('.sw-skeleton').should('not.exist');
                cy.get('.sw-loader').should('not.exist');
            });
    });

    it('@visual: check appearance of basic sales channel workflow', { tags: ['pa-sales-channels'] }, () => {
        // Request we want to wait for later
        cy.intercept({
            url: `${Cypress.env('apiPath')}/sales-channel`,
            method: 'POST',
        }).as('saveData');

        // Open sales channel
        cy.contains('Storefront').click();
        cy.get('.sw-page__main-content').should('be.visible');
        cy.get('.sw-skeleton__detail').should('not.exist');
        cy.get('#sw-field--salesChannel-name').should('be.visible');

        // Take snapshot for visual testing
        cy.contains('.sw-sales-channel-detail__select-customer-group .sw-entity-single-select__selection',
            'Standard customer group');
        cy.contains('.sw-sales-channel-detail__assign-countries .sw-entity-single-select__selection',
            'Germany');
        cy.contains('.sw-sales-channel-detail__assign-languages .sw-entity-single-select__selection',
            'English');

        // Change display of the element to ensure consistent snapshots
        cy.changeElementStyling(
            '.sw-entity-multi-select .sw-select-selection-list',
            'display: none',
        );
        cy.get('.sw-entity-multi-select .sw-select-selection-list')
            .should('have.css', 'display', 'none');

        // Change background-color of the element to ensure consistent snapshots
        cy.changeElementStyling(
            '.sw-entity-multi-select .sw-select__selection',
            'background-color: #189EF',
        );

        cy.get('.sw-entity-multi-select .sw-select__selection')
            .should('have.css', 'color', 'rgb(82, 102, 122)');

        cy.get('.sw-tabs-item').eq(1).contains('Products');
        cy.get('.sw-tabs-item').eq(2).contains('Theme');
        cy.get('.sw-tabs-item').eq(3).contains('Analytics');

        cy.prepareAdminForScreenshot();
        cy.takeSnapshot('[Sales channel] Detail', '.sw-sales-channel-detail-base', null, {percyCSS: '.sw-notification-center__context-button--new-available:after { display: none; }'});
    });
});

