'use strict';

const mailsac     = require('./mailsac');
const intercepted = require('./intercepted-mail');

const isMailsac = () => process.env.EMAIL_BACKEND === 'mailsac';

module.exports = {
    getMessages:        (...a) => isMailsac() ? mailsac.getMessages(...a)        : intercepted.getMessages(...a),
    purgeInbox:         (...a) => isMailsac() ? mailsac.purgeInbox(...a)         : intercepted.purgeInbox(...a),
    waitForMessages:    (...a) => isMailsac() ? mailsac.waitForMessages(...a)    : intercepted.waitForMessages(...a),
    waitForNewMessages: (...a) => isMailsac() ? mailsac.waitForNewMessages(...a) : intercepted.waitForNewMessages(...a),
    getEmailBody:       (...a) => isMailsac() ? mailsac.getEmailBody(...a)       : intercepted.getEmailBody(...a),
    assertDelivered:    (...a) => isMailsac() ? mailsac.assertDelivered(...a)    : intercepted.assertDelivered(...a),
};
