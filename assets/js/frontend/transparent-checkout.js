/**
 * global wc_triibo_assinaturas_params, wc_checkout_params
 */

( function( $ )
{
    "use strict";

    $( function()
    {
        var triibo_assinaturas_submit = false;

        /**
         * Set credit card brand.
         *
         * @param {string} brand
         */
        function triiboAssinaturasSetCreditCardBrand( brand )
        {
            $( "#wc-triibo_assinaturas-cc-form" ).attr( "data-credit-card-brand", brand );
        }

        /**
         * Add error message
         *
         * @param {string} error
         */
        function triiboAssinaturasAddErrorMessage( error )
        {
            var wrapper = $( "#wc-triibo_assinaturas-cc-form" );

            $( ".woocommerce-error", wrapper ).remove();

            wrapper.prepend( `<div class="woocommerce-error" style="margin-bottom: 0.5em !important;" >${error}</div>` );
        }

        /**
         * Initialize mask of the payment form.
         */
        function triiboAssinaturasInitPaymentForm()
        {
            $( "#wc-triibo_assinaturas-cc-form" ).show();

            // CPF.
            $( "#triibo_assinaturas-card-holder-cpf" ).mask( "000.000.000-00" );

            // Birth Date.
            $( "#triibo_assinaturas-card-holder-birth-date" ).mask( "00/00/0000" );

            // Phone.
            var MaskBehavior = function( val ) {
                    return val.replace( /\D/g, "" ).length === 13 ? "+55 (00) 00000-0000" : "+55 (00) 0000-00009";
                },
                maskOptions = {
                    onKeyPress: function( val, e, field, options ) {
                        field.mask( MaskBehavior.apply( {}, arguments ), options );
                    }
                };

            $( "#triibo_assinaturas-card-holder-phone" ).mask( MaskBehavior, maskOptions );
        }

        /**
         * Form Handler.
         *
         * @return {bool}
         */
        function triiboAssinaturasformHandler()
        {
            if ( triibo_assinaturas_submit )
            {
                triibo_assinaturas_submit = false;
                return true;
            }

            if ( ! $( "#payment_method_triibo_assinaturas" ).is( ":checked" ) ) return true;

            var form            = $( "form.checkout, form#order_review, form#add_payment_method" ),
                error           = false,
                errorHtml       = "",

                holder          = $( "#triibo_assinaturas-card-holder-name",       form ).val(),
                h_cpf           = $( "#triibo_assinaturas-card-holder-cpf",        form ).val(),
                h_birth         = $( "#triibo_assinaturas-card-holder-birth-date", form ).val(),
                h_phone         = $( "#triibo_assinaturas-card-holder-phone",      form ).val(),

                cardNumber      = $( "#triibo_assinaturas-card-number", form ).val().replace( /\s+/g,  "-" ),
                expirationMonth = $( "#triibo_assinaturas-card-expiry", form ).val().replace( /[^\d]/g, "" ).substr( 0, 2 ),
                expirationYear  = $( "#triibo_assinaturas-card-expiry", form ).val().replace( /[^\d]/g, "" ).substr( 2 ),
                cvc             = $( "#triibo_assinaturas-card-cvc",    form ).val(),

                creditCardForm  = $( "#wc-triibo_assinaturas-cc-form",  form ),
                brand           = creditCardForm.attr( "data-credit-card-brand" ),
                today           = new Date();

            // Validate the credit card data.
            errorHtml += "<ul>";

            // Validate the card brand.
            if ( typeof brand === "undefined" || brand === "error" )
            {
                errorHtml += `<li>${wc_triibo_assinaturas_params.messages.invalid_card}</li>`;
                error      = true;
            }
            // Validate the card brand.
            if ( brand === "unsupported" )
            {
                errorHtml += `<li>${wc_triibo_assinaturas_params.messages.unsupported_card}</li>`;
                error      = true;
            }

            // Allow for year to be entered either as 2 or 4 digits
            if ( expirationYear.length === 2 )
            {
                let prefix     = today.getFullYear().toString().substr( 0, 2 );
                expirationYear = prefix + "" + expirationYear;
            }

            // Validate the expiry date.
            if ( expirationMonth.length !== 2 || expirationYear.length !== 4 )
            {
                errorHtml += `<li>${wc_triibo_assinaturas_params.messages.invalid_expiry}</li>`;
                error      = true;
            }
            if ( ( expirationMonth.length === 2 && expirationYear.length === 4 ) &&
                ( expirationMonth > 12                           ||
                  expirationYear <= ( today.getFullYear() - 1 )  ||
                  expirationYear >= ( today.getFullYear() + 20 ) ||
                 ( expirationMonth < ( today.getMonth() + 2 ) && expirationYear.toString() === today.getFullYear().toString() ) ) )
            {
                errorHtml += `<li>${wc_triibo_assinaturas_params.messages.expired_date}</li>`;
                error      = true;
            }

            // // Validate cvv code
            // if ( typeof cvv === 'undefined' || cvv === null || cvv.length < 3 )
            // {
            //     errorHtml += `<li>${wc_triibo_assinaturas_params.messages.invalid_cvv}</li>`;
            //     error      = true;
            // }

            // Validate holder name
            if ( typeof holder === 'undefined' || holder === null || holder === "" )
            {
                errorHtml += `<li>${wc_triibo_assinaturas_params.messages.invalid_holder}</li>`;
                error      = true;
            }

            // Validate holder CPF
            if ( typeof h_cpf === 'undefined' || h_cpf === null || h_cpf === "" )
            {
                errorHtml += `<li>${wc_triibo_assinaturas_params.messages.invalid_h_cpf}</li>`;
                error      = true;
            }

            // Validate holder birthdate
            if ( typeof h_birth === 'undefined' || h_birth === null || h_birth === "" )
            {
                errorHtml += `<li>${wc_triibo_assinaturas_params.messages.invalid_h_birth}</li>`;
                error      = true;
            }

            // Validate holder phone
            if ( typeof h_phone === 'undefined' || h_phone === null || h_phone === "" )
            {
                errorHtml += `<li>${wc_triibo_assinaturas_params.messages.invalid_h_phone}</li>`;
                error      = true;
            }

            errorHtml += "</ul>";

            if ( error )
            {
                triiboAssinaturasAddErrorMessage( errorHtml );
            }
            else
            {
                form.append( $( "<input name='triibo_assinaturas-holder'      type='hidden' />" ).val( holder ) );
                form.append( $( "<input name='triibo_assinaturas-h_cpf'       type='hidden' />" ).val( h_cpf ) );
                form.append( $( "<input name='triibo_assinaturas-h_birth'     type='hidden' />" ).val( h_birth ) );
                form.append( $( "<input name='triibo_assinaturas-h_phone'     type='hidden' />" ).val( h_phone ) );
                form.append( $( "<input name='triibo_assinaturas-card_number' type='hidden' />" ).val( cardNumber ) );
                form.append( $( "<input name='triibo_assinaturas-cvc'         type='hidden' />" ).val( cvc ) );
                form.append( $( "<input name='triibo_assinaturas-exp_month'   type='hidden' />" ).val( expirationMonth ) );
                form.append( $( "<input name='triibo_assinaturas-exp_year'    type='hidden' />" ).val( expirationYear ) );
                form.append( $( "<input name='triibo_assinaturas-brand'       type='hidden' />" ).val( brand ) );

                // Submit the form.
                triibo_assinaturas_submit = true;
                form.submit();
            }

            return false;
        }


        // Display the payment for and init the input masks.
        if ( typeof wc_checkout_params != "undefined" && wc_checkout_params.is_checkout === "1" )
        {
            $( "body" ).on( "updated_checkout", function() {
                triiboAssinaturasInitPaymentForm();
            } );
        }
        else
        {
            triiboAssinaturasInitPaymentForm();
        }


        // Get the credit card brand.
        $( "body" ).on( "focusout", "#triibo_assinaturas-card-number", function() {
            let cardNumber = $( this ).val();

            if ( cardNumber.length === 19 )
            {
                cardNumber = cardNumber.replace( /\s+/g, "-" );

                $.ajax( {
                    url  : `${wc_triibo_assinaturas_params.base_url}/brand/${cardNumber}`,
                    type : "GET",
                    success: function( data ) {
                        if ( data.success )
                        {
                            if ( data.brandInfo.supported )
                            {
                                $( "body" ).trigger( "triibo_assinaturas_credit_card_brand", data.brandInfo.brand );
                                triiboAssinaturasSetCreditCardBrand( data.brandInfo.brand );
                            }
                            else
                            {
                                $( "body" ).trigger( "triibo_assinaturas_credit_card_brand", "unsupported" );
                                triiboAssinaturasSetCreditCardBrand( "unsupported" );
                            }
                        }
                        else
                        {
                            $( "body" ).trigger( "triibo_assinaturas_credit_card_brand", "error" );
                            triiboAssinaturasSetCreditCardBrand( "error" );
                        }
                    },
                    error: function( err ) {
                        console.log( err );
                        $( "body" ).trigger( "triibo_assinaturas_credit_card_brand", "error" );
                        triiboAssinaturasSetCreditCardBrand( "error" );
                    }
                } );
            }
        } );

        $( "body" ).on( "updated_checkout", function() {
            var field = $( "body #triibo_assinaturas-card-number" );

            if ( 0 < field.length ) field.focusout();
        } );


        // Set the errors.
        $( "body" ).on( "focus", "#triibo_assinaturas-card-number, #triibo_assinaturas-card-expiry", function() {
            $( "#wc-triibo_assinaturas-cc-form .woocommerce-error" ).remove();
        } );

        $( "body" ).on( "triibo_assinaturas_credit_card_brand", function( event, brand ) {
            if ( "unsupported" == brand )
            {
                triiboAssinaturasAddErrorMessage( wc_triibo_assinaturas_params.messages.unsupported_card );
            }
            else if ( "error" == brand )
            {
                triiboAssinaturasAddErrorMessage( wc_triibo_assinaturas_params.messages.invalid_card );
            }
        });


        // Process the credit card data when submit the checkout form.
        $( "form.checkout" ).on( "checkout_place_order_triibo_assinaturas", function() {
            return triiboAssinaturasformHandler();
        } );

        $( "form#order_review" ).submit( function() {
            return triiboAssinaturasformHandler();
        } );

        $( "form#add_payment_method" ).submit( function() {
            return triiboAssinaturasformHandler();
        } );
    } );

}( jQuery ) );
