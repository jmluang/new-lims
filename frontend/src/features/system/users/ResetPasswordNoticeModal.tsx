import { Button, Modal } from '../shared'
import { resetPasswordSuccessMessage } from './userPasswordReset'

export function ResetPasswordNoticeModal({
  userName,
  password,
  onClose,
}: {
  userName: string
  password: string
  onClose: () => void
}) {
  return (
    <Modal title="密码已重置" description="请将临时密码告知用户。" open onClose={onClose}>
      <div className="space-y-4">
        <p className="text-sm leading-6 text-slate-700">{resetPasswordSuccessMessage(userName, password)}</p>
        <div className="rounded-md border border-emerald-200 bg-emerald-50 p-3">
          <div className="text-xs font-medium text-emerald-800">临时密码</div>
          <div className="mt-1 font-mono text-lg font-semibold text-emerald-950">{password}</div>
        </div>
        <div className="flex justify-end">
          <Button variant="primary" onClick={onClose}>
            我知道了
          </Button>
        </div>
      </div>
    </Modal>
  )
}
