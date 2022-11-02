## 1.x

### 1.0.0

 - Added `EventLoop::getIdentifiers` (#62)
 - Added `EventLoop::getType` (#62)
 - Added `EventLoop::isEnabled` (#62)
 - Added `EventLoop::isReferenced` (#62)
 - Fixed `EventLoop::getErrorHandler` missing the `static` modifier
 - Fixed double wrapping in `UncaughtThrowable` if a decorating event loop driver throws an `UncaughtThrowable` (#61)
 - Removed `EventLoop::getInfo`, use `EventLoop::getIdentifiers()` in combination with `EventLoop::isEnabled`, `EventLoop::isReferenced`, and `EventLoop::getType` instead  (#62)
 - Removed `EventLoop::createSuspension`, use `EventLoop::getSuspension` instead

## 0.2.x

### 0.2.5

 - PHP 8.1 is now required (#55)
 - Fixed compatibility with 8.2 by fixing a deprecation notice (#58)
 - Fixed an integer overflow on timers if a large (e.g. `PHP_INT_MAX`) timeout is requested (#49)
 - Removed the reference kept to microtask (`EventLoop::queue()`) callback arguments so passed objects may be garbage collected if a resulting fiber unsets all references to the argument (#60)

### 0.2.4

 - Fixed the fiber reference in `DriverSuspension` being nulled early during shutdown, leading to an assertion error when attempting to resume the suspension

### 0.2.3

 - Fixed `Undefined property: Revolt\EventLoop\Internal\DriverSuspension::$fiber` in an error path

### 0.2.2

 - Fixed memory leak with suspensions keeping a reference to fibers (#42, #52)
   Similar leaks might still happen if suspensions are never resumed, so ensure your suspensions are eventually resumed.

### 0.2.1

 - Added template type to `Suspension` (#44)
 - Added `FiberLocal::unset()` (#45)
 - Added stacktrace to all current suspensions on early exit of the event loop (#46)

### 0.2.0

 - Added `FiberLocal` to store data specific to each fiber, e.g. logging context (#40)
 - Added throwing `UnhandledThrowable` if event loop stops due to an exception (#32)
 - Added `EventLoop::getErrorHandler()` to get the currently set error handler
 - Improved performance by reducing fiber switches by queueing callbacks for each tick (#34)
 - Improved performance by not creating unnecessary fibers if exceptions are thrown from callbacks
 - Removed return value of `EventLoop::setErrorHandler()`, use `EventLoop::getErrorHandler()` instead
 - Removed default value for first argument of `EventLoop::setErrorHandler()` (#30)
 - Cache suspensions and always return the same value for a specific fiber (#37)
     - `EventLoop::getSuspension()` has been added as replacement for `EventLoop::createSuspension()`
     - `EventLoop::createSuspension()` has been deprecated and will be removed in the next version
 - Fixed multiple interrupts on double resumption leading to an assertion error instead of an exception (#41)
 - Fixed suspensions keeping their pending state after the event loop exceptionally stopped

## 0.1.x

### 0.1.1

 - Fixed exceptions being hidden if the event loop stopped due to an uncaught exception (#31)

### 0.1.0

Initial development release.
