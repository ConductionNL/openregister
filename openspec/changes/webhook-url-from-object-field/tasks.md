# Tasks: webhook-url-from-object-field

## 1. The dialect

- [x] 1.1 `AnnotationNotificationDispatcher::postWebhook()` takes the URL from
      `webhook.urlField` on the object when the rule names one; empty or
      malformed sends nothing and logs why.
- [x] 1.2 `NotificationAnnotationValidator` accepts `urlField` (a property name)
      in place of `url` on a non-persistent webhook.

## 2. Tests

- [x] 2.1 `AnnotationNotificationDispatcherTest`: the POST goes to the object's
      URL; absent, empty, non-string and malformed values send nothing and log.
- [x] 2.2 `NotificationAnnotationValidatorTest`: `urlField` accepted; empty,
      non-name, non-string and persistent refused.
