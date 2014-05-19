window.paymentspring = (function () {

    function PaymentSpring() {
        this.script = null;
        this.callback = null;
        this.key = null;
    }

    PaymentSpring.prototype.generateToken = function (public_key, card_number, csc, card_holder, exp_month, exp_year, callback) {
        if (this.script) return;
        this.key = public_key;
        this.callback = callback;
        this.script = document.createElement("script");
        this.script.type = "text/javascript";
        this.script.id = "tempscript";
        this.script.src = "http://paymentspring.dev:9296/api/v1/tokens/jsonp"
        + "?public_api_key=" + this.key
        + "&card_number=" + card_number
        + "&csc=" + csc
        + "&card_exp_month=" + exp_month
        + "&card_exp_year=" + exp_year
        + "&card_owner_name=" + card_holder
        + "&callback=paymentspring.onComplete";

        document.body.appendChild(this.script);
    };

    PaymentSpring.prototype.onComplete = function(data) {
        document.body.removeChild(this.script);
        this.script = null;
        this.callback(data);
    }

    return new PaymentSpring();
}());
