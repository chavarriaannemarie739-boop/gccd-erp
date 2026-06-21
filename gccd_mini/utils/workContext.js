const PROCESS_NAMES = ['断钢', '穿孔', '球化', '矫直', '酸洗'];

const entryTypeForProcess = (name) => {
  if (name === '断钢') {
    return 'duangang_merge';
  }
  if (name === '穿孔') {
    return 'punch_device';
  }
  return 'process_task';
};

const resolveFromHaystack = (haystack) => {
  for (let i = 0; i < PROCESS_NAMES.length; i += 1) {
    const name = PROCESS_NAMES[i];
    if (name && haystack.indexOf(name) !== -1) {
      return {
        processes_code: '',
        processes_name: name,
        entry_type: entryTypeForProcess(name),
      };
    }
  }
  return {
    processes_code: '',
    processes_name: '',
    entry_type: 'generic',
  };
};

const resolveWorkContextFromStorage = () => {
  const deptNames = wx.getStorageSync('dept_names') || '';
  const nickname = wx.getStorageSync('nickname') || '';
  return resolveFromHaystack(`${deptNames} ${nickname}`);
};

const buildWorkEntry = (ctx) => {
  const name = ctx.processes_name || '生产';
  let url = '';
  let desc = '进入本工序任务列表';

  if (ctx.entry_type === 'duangang_merge') {
    url = '/pageWorkOrder/pages/duangangList/duangangList';
    desc = '断钢合并报工';
  } else if (ctx.entry_type === 'punch_device') {
    url = '/pageWorkOrder/pages/punchDevice/punchDevice';
    desc = '先选穿孔设备再看任务';
  } else if (ctx.processes_code) {
    url = `/pageWorkOrder/pages/processTaskList/processTaskList?processes_code=${ctx.processes_code}&processes_name=${encodeURIComponent(ctx.processes_name || '')}`;
  } else if (ctx.processes_name) {
    url = `/pageWorkOrder/pages/processTaskList/processTaskList?processes_name=${encodeURIComponent(ctx.processes_name)}`;
  } else {
    url = '/pages/workOrder/workOrder';
    desc = '通用工单（未识别工序）';
  }

  return {
    reportTitle: `${name}报工`,
    reportDesc: desc,
    reportUrl: url,
    ctx,
  };
};

const applyWorkContext = (ctx) => {
  wx.setStorageSync('work_processes_code', ctx.processes_code || '');
  wx.setStorageSync('work_processes_name', ctx.processes_name || '');
  wx.setStorageSync('work_entry_type', ctx.entry_type || 'generic');
  return buildWorkEntry(ctx);
};

module.exports = {
  resolveWorkContextFromStorage,
  applyWorkContext,
};
