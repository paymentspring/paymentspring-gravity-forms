window.paymentspring = (function () {

    var jsonpTimeout;
    var jsonpURL = "https://api.paymentspring.com/api/v1/tokens/jsonp";

    /**
     * @constructor
     */
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
        this.script.id = "paymentspring_request_script";
        this.script.src = jsonpURL
        + "?public_api_key=" + this.key
        + "&card_number=" + card_number
        + "&csc=" + csc
        + "&card_exp_month=" + exp_month
        + "&card_exp_year=" + exp_year
        + "&card_owner_name=" + card_holder
        + "&callback=paymentspring.onComplete";

        document.body.appendChild(this.script);
        var closure_this = this;
        jsonpTimeout = setTimeout(function() { document.body.removeChild(closure_this.script); closure_this.script = null; callback(null); }, 5000);
    };

    PaymentSpring.prototype.onComplete = function(data) {
        clearTimeout(jsonpTimeout);
        document.body.removeChild(this.script);
        this.script = null;
        this.callback(data);
    };

    PaymentSpring.prototype.validateCardNumber = function (card_number) {
        card_number += "";
        if (!/^[\d- ]+$/.test(card_number)) {
            // A character that is not a number, hyphen, or space was found.
            return false;
        }
        // Strip out all non-numeric characters.
        card_number = card_number.replace(/[^\d]*/g, "");

        if (card_number.length < 10 || card_number.length > 16) {
            return false;
        }

        // Perform Luhn check.
        card_number = card_number.split("").reverse();
        var total = 0;
        for (var i = 0; i < card_number.length; i++) {
            var d = parseInt(card_number[i], 10);
            if (i % 2 == 0) {
                total += d;
            } else if (d * 2 > 9) {
                total += (d * 2) - 9;
            } else {
                total += d * 2;
            }
        }
        return total % 10 == 0;
    };

    PaymentSpring.prototype.validateCardExpDate = function (exp_month, exp_year) {
        var now = new Date();
        var exp = new Date(exp_year, exp_month - 1); // January is month 0
        var future = new Date(now.getFullYear() + 25, now.getMonth()); // 25 years in the future
        if (exp > now && future > exp) {
            return true;
        } else {
            return false;
        }
    };

    PaymentSpring.prototype.validateCSC = function (csc) {
        csc += "";
        if (/^\d+$/.test(csc) && csc.length >= 3 && csc.length <= 4) {
            return true;
        } else {
            return false;
        }
    };

    PaymentSpring.prototype.validateName = function (name) {
        if (name.length > 0) {
            return true;
        } else {
            return false;
        }
    };

    return new PaymentSpring();
}());
