/**
 * Retry Policy with Exponential Backoff and Jitter
 * Handles transient network errors and server failures.
 */

export class RetryPolicy {
  constructor(options = {}) {
    this.maxRetries = options.maxRetries ?? 3;
    this.baseDelay = options.baseDelay ?? 1000;
    this.maxDelay = options.maxDelay ?? 30000;
    this.jitter = options.jitter ?? true;
    this.backoffMultiplier = options.backoffMultiplier ?? 2;
  }

  getDelay(attempt) {
    const exponentialDelay = Math.min(
      this.baseDelay * Math.pow(this.backoffMultiplier, attempt),
      this.maxDelay
    );
    const jitterAmount = this.jitter ? Math.random() * 0.3 * exponentialDelay : 0;
    return Math.floor(exponentialDelay + jitterAmount);
  }

  async execute(fn, shouldRetry, signal) {
    const retryCondition = shouldRetry ?? ((error) => {
      return error.name === 'NetworkError' ||
             error.name === 'TypeError' ||
             error.name === 'TimeoutError' ||
             error.status >= 500 ||
             error.code === 'ECONNRESET' ||
             error.code === 'ETIMEDOUT' ||
             error.code === 'ENOTFOUND';
    });

    let lastError;

    for (let attempt = 0; attempt <= this.maxRetries; attempt++) {
      if (signal?.aborted) {
        throw new DOMException('Request aborted', 'AbortError');
      }
      try {
        return await fn();
      } catch (error) {
        if (signal?.aborted) {
          throw new DOMException('Request aborted', 'AbortError');
        }
        lastError = error;

        if (attempt === this.maxRetries || !retryCondition(error)) {
          throw error;
        }

        await this.sleep(this.getDelay(attempt), signal);
      }
    }

    throw lastError;
  }

  sleep(ms, signal) {
    return new Promise((resolve, reject) => {
      if (signal?.aborted) {
        reject(new DOMException('Request aborted', 'AbortError'));
        return;
      }
      const timeout = setTimeout(() => {
        signal?.removeEventListener('abort', abort);
        resolve();
      }, ms);
      const abort = () => {
        clearTimeout(timeout);
        reject(new DOMException('Request aborted', 'AbortError'));
      };
      signal?.addEventListener('abort', abort, { once: true });
    });
  }

  reset() {
    this.maxRetries = 3;
    this.baseDelay = 1000;
    this.maxDelay = 30000;
    this.jitter = true;
  }
}
