describe('Seo: Test crud operations on templates', () => {
    const routeNames = {
        'Product detail page': 'product',
        'Landing page': 'landingPage',
        'Category page': 'category',
    };

    beforeEach(() => {
        cy.createCategoryFixture({
            parent: {
                name: 'ParentCategory',
                active: true,
            },
        })
            .then(() => {
                let salesChannel;
                return cy.searchViaAdminApi({
                    endpoint: 'sales-channel',
                    data: {
                        field: 'name',
                        type: 'equals',
                        value: 'Storefront',
                    },
                }).then((data) => {
                    salesChannel = data.id;
                    cy.createDefaultFixture('cms-page', {}, 'cms-landing-page');
                }).then((data) => {
                    cy.createDefaultFixture('landing-page', {
                        cmsPage: data,
                        name: 'Some landing page',
                        url: 'some-landing-page',
                        salesChannels: [
                            {
                                id: salesChannel,
                            },
                        ],
                    }, 'landing-page');
                });
            })
            .then(() => {
                return cy.createProductFixture();
            })
            .then(() => {
                return cy.createProductFixture({
                    name: 'Awesome product',
                    productNumber: 'RS-1337',
                    description: 'l33t',
                    price: [
                        {
                            currencyId: 'b7d2554b0ce847cd82f3ac9bd1c0dfca',
                            net: 24,
                            linked: false,
                            gross: 128,
                        },
                    ],
                });
            })
            .then(() => {
                cy.openInitialPage(`${Cypress.env('admin')}#/sw/settings/seo/index`);
                cy.get('.sw-skeleton').should('not.exist');
                cy.get('.sw-loader').should('not.exist');
            });
    });

    it('@settings: update template', { tags: ['pa-sales-channels'] }, () => {
        cy.intercept({
            url: `${Cypress.env('apiPath')}/_action/sync`,
            method: 'POST',
        }).as('templateSaveCall');

        cy.get('.sw-seo-url-template-card__seo-url').should('have.length', 3);

        // Should have 3 valid templates on load
        cy.get('.icon--regular-checkmark').should('have.length', 3);

        // Type the most simple url template, which prints the id
        cy.get('#sw-field--seo-url-template-undefined').first().clear().type(`{{${routeNames['Product detail page']}.id}}`, { parseSpecialCharSequences: false });
        // ids are 16 hex chars
        cy.get('.sw-seo-url-template-card__preview-item').first().contains(/[a-z0-9]{16}/);

        // Type the most simple url template, which prints the id
        cy.contains('label', 'Landing page')
            .parent()
            .parent()
            .find('input')
            .clear().type(`{{${routeNames['Landing page']}.id}}`, { parseSpecialCharSequences: false });

        // ids are 16 hex chars
        cy.get('.sw-seo-url-template-card__preview-item').eq(1).contains(/[a-z0-9]{16}/);

        // Type the most simple url template, which prints the id
        cy.contains('label', 'Category page')
            .parent()
            .parent()
            .find('input')
            .clear().type(`{{${routeNames['Category page']}.id}}`, { parseSpecialCharSequences: false });

        // ids are 16 hex chars
        cy.get('.sw-seo-url-template-card__preview-item').eq(2).contains(/[a-z0-9]{16}/);

        // check that the templates can be saved
        cy.contains('.smart-bar__actions', 'Save').click();
        cy.wait('@templateSaveCall').its('response.statusCode').should('equal', 200);
    });

    it('@base @settings: update template for a sales channel', { tags: ['pa-sales-channels'] }, () => {
        cy.intercept({
            url: `${Cypress.env('apiPath')}/_action/sync`,
            method: 'POST',
        }).as('templateCreateCall');

        // check inherited saleschannel templates
        cy.get('.sw-sales-channel-switch')
            .typeSingleSelectAndCheck('Storefront', '.sw-entity-single-select');

        // assert that all inputs are disabled
        cy.get('.sw-seo-url-template-card').find('.sw-card__content').within(() => {
            cy.get('input').should('be.disabled');
        });

        // foreach card ...
        Object.keys(routeNames).forEach((routeName, key) => {
            cy.contains(routeName)
                .parentsUntil('.sw-seo-url-template-card__seo-url')
                .parent().eq(key).within(() => {
                // ... check that the inheritance can be removed
                    cy.get('.sw-inheritance-switch').click();
                    cy.get('input').should('not.be.disabled');
                    // ... and that the preview works
                    cy.get('.icon--regular-checkmark');
                });
        });

        //
        cy.contains('.smart-bar__actions', 'Save').click();
        cy.wait('@templateCreateCall').its('response.statusCode').should('equal', 200);
        cy.awaitAndCheckNotification('SEO URL templates have been saved.');
    });

    it('@base @settings: cannot edit templates for headless sales channels', { tags: ['pa-sales-channels'] }, () => {
        cy.get('.sw-sales-channel-switch')
            .typeSingleSelectAndCheck('Headless', '.sw-entity-single-select');

        cy.contains('.sw-card__content', 'SEO URLs cannot be assigned to a headless Sales Channel.');
    });

    it('@base @settings: can save when the first template is empty', { tags: ['pa-sales-channels'] }, () => {
        cy.intercept({
            url: `${Cypress.env('apiPath')}/_action/sync`,
            method: 'POST',
        }).as('templateSaveCall');

        cy.get('.sw-block-field__block #sw-field--seo-url-template-undefined')
            .eq(0)
            .should('be.visible')
            .clear()
            .should('be.empty');

        // check that no error is thrown
        cy.contains('.smart-bar__actions', 'Save').click();
        cy.wait('@templateSaveCall').its('response.statusCode').should('equal', 200);
        cy.get('.sw-field__error').should('not.exist');

        // ensure the template is still visible
        cy.get('.sw-block-field__block #sw-field--seo-url-template-undefined')
            .eq(0)
            .should('be.visible');
    });

    it('@base @settings: can save when the second template is empty', { tags: ['pa-sales-channels'] }, () => {
        cy.intercept({
            url: `${Cypress.env('apiPath')}/_action/sync`,
            method: 'POST',
        }).as('templateSaveCall');

        cy.get('.sw-block-field__block #sw-field--seo-url-template-undefined')
            .eq(1)
            .should('be.visible')
            .clear()
            .should('be.empty');

        // check that no error is thrown
        cy.contains('.smart-bar__actions', 'Save').click();
        cy.wait('@templateSaveCall').its('response.statusCode').should('equal', 200);
        cy.get('.sw-field__error').should('not.exist');

        // ensure the template is still visible
        cy.get('.sw-block-field__block #sw-field--seo-url-template-undefined')
            .eq(1)
            .should('be.visible');
    });

    it('@base @settings: can save when the third template is empty', { tags: ['pa-sales-channels'] }, () => {
        cy.intercept({
            url: `${Cypress.env('apiPath')}/_action/sync`,
            method: 'POST',
        }).as('templateSaveCall');

        cy.get('.sw-block-field__block #sw-field--seo-url-template-undefined')
            .eq(2)
            .should('be.visible')
            .clear()
            .should('be.empty');

        // check that no error is thrown
        cy.contains('.smart-bar__actions', 'Save').click();

        cy.wait('@templateSaveCall')
            .its('response.statusCode').should('equal', 200);
        cy.get('.sw-field__error').should('not.exist');

        // ensure the template is still visible
        cy.get('.sw-block-field__block #sw-field--seo-url-template-undefined')
            .eq(2)
            .should('be.visible');
    });
});
