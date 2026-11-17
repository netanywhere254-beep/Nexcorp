# Preserved MongoDB / Mongoose submissions

**Purpose:** preserve the user's MongoDB-related work without inserting MongoDB code into the active Laravel/MySQL or NestJS/PostgreSQL applications.

These are reference examples, not approved production code. The same Mongoose User model was submitted twice; it is recorded once below with the duplicate noted.

## 1. User model (submitted twice)

```js
const mongoose = require('mongoose');
const userSchema = new mongoose.Schema({
    username: { type: String, required: true, unique: true },
    email: { type: String, required: true, unique: true },
    password: { type: String, required: true },
    role: { type: String, required: true }
});
module.exports = mongoose.model('User', userSchema);
```

**Review:** useful identity fields, but passwords must be hashed, public registration must not choose privileged roles, account status and tenant memberships are required, and unique indexes must match the intended tenant/email rules. Rebuild using the selected SQL schemas unless MongoDB is later approved for a separate use case.

## 2. Subscription controller

```js
const Subscription = require('../models/NexISPModel');
const UserAccount = require('../models/UserAccountModel');
exports.subscribeToPlan = async (req, res) => {
    try {
        const { planId, durationMonths } = req.body;
        const userId = req.user.userId;
        const newSub = new Subscription({
            userId,
            planId,
            startDate: new Date(),
            durationMonths,
            status: 'active'
        });
        await newSub.save();
        await UserAccount.findByIdAndUpdate(userId, {
            currentSubscription: newSub._id
        });
        res.status(201).json({ success: true, message: 'Subscription activated', data: newSub });
    } catch (error) {
        res.status(500).json({ success: false, message: 'Subscription failed', error: error.message });
    }
};
exports.checkServiceStatus = async (req, res) => {
    try {
        const userId = req.user.userId;
        const lastActive = await Subscription.findOne({ userId }).sort({ createdAt: -1 });
        if (!lastActive || lastActive.status !== 'active') {
            return res.json({ success: false, connected: false, reason: 'No active service' });
        }
        res.json({ success: true, connected: true, plan: lastActive.planId });
    } catch (error) {
        res.status(500).json({ success: false, message: 'Service check failed' });
    }
};
```

**Review:** retain subscription and service-status concepts. Do not activate a paid subscription before verified payment; enforce tenant scope, validate the plan and duration, use transactions, and derive the customer from authenticated server-side context.

## 3. Loopza post and feed controller

```js
const Post = require('../models/LoopzaModel');
exports.createPost = async (req, res) => {
    try {
        const { content, mediaUrl } = req.body;
        const authorId = req.user.userId;
        const newPost = new Post({
            author: authorId,
            content,
            media: mediaUrl,
            timestamp: new Date()
        });
        await newPost.save();
        res.status(201).json({ success: true, post: newPost });
    } catch (error) {
        res.status(500).json({ success: false, message: 'Failed to create post' });
    }
};
exports.getFeed = async (req, res) => {
    try {
        const limit = parseInt(req.query.limit) || 10;
        const posts = await Post.find().sort({ timestamp: -1 }).limit(limit);
        res.json({ success: true, feed: posts });
    } catch (error) {
        res.status(500).json({ success: false, message: 'Failed to load feed' });
    }
};
```

**Review:** retain publishing and feed concepts. Add tenant/country visibility, moderation and publication status, media validation, pagination limits, and authorization. Do not expose a global unfiltered feed across tenants.

## 4. Request telemetry middleware

```js
const AuditLog = require('../models/AuditLogModel');
exports.requestLogger = async (req, res, next) => {
    const startTime = Date.now();
    res.on('finish', async () => {
        const duration = Date.now() - startTime;
        try {
            await AuditLog.create({
                method: req.method,
                endpoint: req.originalUrl,
                statusCode: res.statusCode,
                responseTime: duration,
                user: req.user ? req.user.userId : 'anonymous',
                ip: req.ip
            });
        } catch (error) {
            console.error('Failed to log telemetry:', error.message);
        }
    });
    next();
};
```

**Review:** keep the telemetry idea, but separate operational request metrics from security audit events. Redact sensitive URL data, apply retention/privacy controls, and use an asynchronous queue. Implement in the selected SQL stacks.

## 5. Related MongoDB-era authentication and payment references

Other submitted Express/JWT, Mongoose registration/login, and Stripe payment/webhook examples are covered by the review register in the conversation. They are not copied into active application code because they contain unsafe patterns such as plaintext password comparison, fallback secrets, user-selected roles, unverified webhook bodies, and client-supplied payment amounts.

## Preservation rule

Do not delete or overwrite these examples when implementing the production versions. Add the secure SQL implementation in its proper application directory and update the architecture decision separately if the owner approves a future MongoDB use case.
