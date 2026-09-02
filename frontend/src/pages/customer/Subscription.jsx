import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../../services/api';
import StatusBadge from '../../components/StatusBadge';

export default function CustomerSubscription() {
  const [subscription, setSubscription] = useState(null);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    api
      .get('/customer/subscription')
      .then(({ data }) => setSubscription(data))
      .catch((err) => {
        if (err.response?.status === 404) {
          setSubscription(null);
        } else {
          setError('Could not load your subscription.');
        }
      })
      .finally(() => setLoading(false));
  }, []);

  if (loading) return <p className="text-sm text-slate-400">Loading…</p>;
  if (error) {
    return <div className="text-sm text-red-700 bg-red-50 border border-red-200 rounded-md px-4 py-3">{error}</div>;
  }

  if (!subscription) {
    return (
      <div className="bg-white rounded-xl border border-slate-200 p-6 text-center">
        <p className="text-slate-600">You don't have a subscription yet.</p>
        <Link to="/buy" className="text-indigo-600 text-sm font-medium hover:underline mt-2 inline-block">
          Browse packages →
        </Link>
      </div>
    );
  }

  return (
    <div>
      <h1 className="text-xl font-semibold text-slate-900 mb-4">My Subscription</h1>

      <div className="bg-white rounded-xl border border-slate-200 p-5 shadow-sm space-y-4">
        <div className="flex items-center justify-between">
          <h2 className="font-semibold text-slate-900">{subscription.package?.name}</h2>
          <StatusBadge status={subscription.status} />
        </div>

        <dl className="grid grid-cols-2 gap-4 text-sm">
          <div>
            <dt className="text-slate-400">Amount Paid</dt>
            <dd className="text-slate-700">GHS {subscription.amount}</dd>
          </div>
          <div>
            <dt className="text-slate-400">Router / Location</dt>
            <dd className="text-slate-700">
              {subscription.router?.name} {subscription.router?.location ? `— ${subscription.router.location}` : ''}
            </dd>
          </div>
          <div>
            <dt className="text-slate-400">Started</dt>
            <dd className="text-slate-700">
              {subscription.starts_at ? new Date(subscription.starts_at).toLocaleString() : '—'}
            </dd>
          </div>
          <div>
            <dt className="text-slate-400">Expires</dt>
            <dd className="text-slate-700">
              {subscription.expires_at ? new Date(subscription.expires_at).toLocaleString() : '—'}
            </dd>
          </div>
        </dl>
      </div>
    </div>
  );
}
